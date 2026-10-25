<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Étape 20 : la MACHINE À SOUS (règles de Pokémon Essentials, Minigame_SlotMachine.rb).
 *
 * C'est le SERVEUR qui fait le tirage :
 *   1. open() : le joueur s'assoit ; on mélange les 3 rouleaux (gardés en cache) et on les envoie au jeu.
 *   2. play($wager) : la mise est retirée. Comme dans les vrais jeux, la machine décide d'abord ce qui sort
 *      (perdu, Cerises, Pikachu, 777...) selon config('game_corner.slots.odds'), puis choisit des positions
 *      d'arrêt des rouleaux qui donnent exactement ce résultat. Le gain est ajouté aux jetons, et le jeu
 *      fait tourner les rouleaux puis les arrête sur ces positions.
 *
 * Mise 1 : ligne du milieu ; 2 : + lignes du haut et du bas ; 3 : + les deux diagonales.
 * Trois REPLAY : la partie suivante est gratuite, avec une mise de 3.
 */
class SlotMachineService
{
    public function __construct(private GameCornerService $corner)
    {
    }

    private function key(Character $character): string
    {
        return "slots:{$character->id}";
    }

    /** S'asseoir à une machine : les rouleaux mélangés, et les jetons */
    public function open(Character $character, int $difficulty): array
    {
        $this->corner->requireCoinCase($character);
        $pool = config("game_corner.slots.reels.{$difficulty}") ?? config('game_corner.slots.reels.1');
        $reels = [];
        for ($r = 0; $r < 3; $r++) {
            $reel = $pool;
            shuffle($reel);
            $reels[] = $reel;
        }
        $session = ['reels' => $reels, 'replay' => false, 'difficulty' => $difficulty];
        Cache::put($this->key($character), $session, now()->addHours(3));

        return ['reels' => $reels, 'coins' => (int) $character->coins, 'max' => $this->corner->max()];
    }

    /**
     * Une partie. Renvoie les positions d'arrêt des rouleaux, les lignes gagnantes, le gain et les jetons.
     * stops[i] = l'index du rouleau i qui se retrouve en HAUT ; le milieu est stops[i]-1, le bas stops[i]-2.
     */
    public function play(Character $character, int $wager): array
    {
        $session = Cache::get($this->key($character));
        if (! $session) {
            throw new InvalidAction('Asseyez-vous d\'abord à une machine.');
        }

        return DB::transaction(function () use ($character, $wager, $session) {
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $replay = (bool) $session['replay'];
            if ($replay) {
                $wager = 3;   // partie gratuite
            } else {
                if ($wager < 1 || $wager > 3) {
                    throw new InvalidAction('Mise impossible.');
                }
                if (! $this->corner->spendCoins($character, $wager)) {
                    throw new InvalidAction('Vous n\'avez pas assez de jetons.');
                }
            }

            $stops = $this->draw($session['reels'], $wager, (int) $session['difficulty']);
            $showing = [];
            foreach ($session['reels'] as $r => $reel) {
                $showing[] = $this->showing($reel, $stops[$r]);
            }
            $result = $this->payout($showing, $wager);
            $this->corner->addCoins($character, $result['payout']);

            $session['replay'] = $result['replay'];
            Cache::put($this->key($character), $session, now()->addHours(3));

            return $result + ['stops' => $stops, 'wager' => $wager, 'free' => $replay, 'coins' => (int) $character->coins];
        });
    }

    /** Le résultat d'une partie : on tire la catégorie (perdu, petit gain...), puis une position des rouleaux qui la donne */
    private function draw(array $reels, int $wager, int $difficulty): array
    {
        $groups = $this->groups($reels, $wager);
        $odds = config("game_corner.slots.odds.{$difficulty}") ?? config('game_corner.slots.odds.1');
        $roll = random_int(0, 99999) / 1000;   // 0 à 99,999 %
        $tier = 'lose';
        // Les chances sont données pour une mise de 3 : avec moins de lignes jouées, on gagne moins souvent
        $scale = $wager / 3;
        foreach (['jackpot', 'mixed', 'big', 'medium', 'small', 'replay'] as $name) {
            $roll -= (float) ($odds[$name] ?? 0) * $scale;
            if ($roll < 0) {
                $tier = $name;
                break;
            }
        }
        $choices = $groups[$tier] ?? [];
        if (! $choices) {
            $choices = $groups['lose'];   // ce résultat est impossible avec ces rouleaux et cette mise : perdu
        }
        $code = $choices[random_int(0, count($choices) - 1)];

        return [intdiv($code, 10000), intdiv($code, 100) % 100, $code % 100];
    }

    /**
     * Toutes les positions d'arrêt possibles (17 x 17 x 17 environ), rangées par catégorie de résultat.
     * Calculé une fois par machine et par mise (gardé en cache). Une position = a * 10000 + b * 100 + c.
     */
    private function groups(array $reels, int $wager): array
    {
        $key = 'slots-groups:' . md5(json_encode($reels)) . ":{$wager}";

        return Cache::remember($key, now()->addHours(3), function () use ($reels, $wager) {
            $groups = ['lose' => [], 'small' => [], 'medium' => [], 'big' => [], 'mixed' => [], 'jackpot' => [], 'replay' => []];
            [$n1, $n2, $n3] = [count($reels[0]), count($reels[1]), count($reels[2])];
            for ($a = 0; $a < $n1; $a++) {
                $s1 = $this->showing($reels[0], $a);
                for ($b = 0; $b < $n2; $b++) {
                    $s2 = $this->showing($reels[1], $b);
                    for ($c = 0; $c < $n3; $c++) {
                        $r = $this->payout([$s1, $s2, $this->showing($reels[2], $c)], $wager);
                        $p = $r['payout'];
                        $tier = match (true) {
                            $r['replay'] && $p === 0 => 'replay',
                            $r['replay'] => null,   // REPLAY + gain : jamais tiré (trop généreux)
                            $p === 0 => 'lose',
                            $p < 8 => 'small',
                            $p < 15 => 'medium',
                            $p < 90 => 'big',
                            $p < 300 => 'mixed',
                            default => 'jackpot',
                        };
                        if ($tier) {
                            $groups[$tier][] = $a * 10000 + $b * 100 + $c;
                        }
                    }
                }
            }

            return $groups;
        });
    }

    /** Les 3 symboles visibles : [haut, milieu, bas] (Essentials : SlotMachineReel#showing) */
    private function showing(array $reel, int $index): array
    {
        $n = count($reel);

        return [$reel[$index], $reel[($index - 1 + $n) % $n], $reel[($index - 2 + $n) % $n]];
    }

    /** Le gain, comme Essentials (pbPayout) */
    public function payout(array $showing, int $wager): array
    {
        [$r1, $r2, $r3] = $showing;
        $lines = [[$r1[1], $r2[1], $r3[1]]];                 // milieu
        if ($wager >= 2) {
            $lines[] = [$r1[0], $r2[0], $r3[0]];            // haut
            $lines[] = [$r1[2], $r2[2], $r3[2]];            // bas
        }
        if ($wager >= 3) {
            $lines[] = [$r1[0], $r2[1], $r3[2]];            // diagonales
            $lines[] = [$r1[2], $r2[1], $r3[0]];
        }

        $pay = config('game_corner.slots.payouts');
        $payout = 0;
        $bonus = 0;
        $replay = false;
        $won = [];
        foreach ($lines as $i => $line) {
            $won[$i] = true;
            $sevens = count(array_filter($line, fn ($s) => $s === 5 || $s === 6));
            if ($line === [1, 1, 1]) {
                $payout += $pay['magnemite'];
            } elseif ($line === [2, 2, 2]) {
                $payout += $pay['shellder'];
            } elseif ($line === [3, 3, 3]) {
                $payout += $pay['pikachu'];
            } elseif ($line === [4, 4, 4]) {
                $payout += $pay['psyduck'];
            } elseif ($line === [5, 5, 5] || $line === [6, 6, 6]) {
                $payout += $pay['seven'];
                $bonus = max($bonus, 2);
            } elseif ($sevens === 3) {
                $payout += $pay['mixed_seven'];
                $bonus = max($bonus, 1);
            } elseif ($line === [7, 7, 7]) {
                $replay = true;
            } elseif ($line[0] === 0) {
                $payout += $line[1] === 0 ? $pay['two_cherries'] : $pay['cherry'];
            } else {
                $won[$i] = false;
            }
        }

        return ['payout' => $payout, 'bonus' => $bonus, 'replay' => $replay, 'lines' => $won];
    }
}
