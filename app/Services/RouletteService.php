<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Étape 20 : la ROULETTE (Rubis / Saphir / Émeraude, d'après le script Essentials de FL).
 *
 * Le plateau : 3 lignes (couleurs) x 4 colonnes (Pokémon) = 12 cases, comme les 12 cases de la roue.
 * Le joueur mise sur :
 *   - une case (un Pokémon d'une couleur) ;
 *   - une colonne (un Pokémon, toutes couleurs) : curseur sur la ligne du haut (y = 0) ;
 *   - une ligne (une couleur, tous les Pokémon) : curseur sur la colonne de gauche (x = 0).
 * Le gain = mise x (12 / nombre de cases encore libres dans la zone choisie) : x12, x6, x4, x3...
 * Une balle ne retombe jamais sur une case déjà sortie. Après 6 balles, le plateau est vidé.
 * La chance de gagner est réglée pour que le rendement moyen soit config('game_corner.roulette.payout_rate').
 *
 * C'est le SERVEUR qui tire la case ; le jeu montre ensuite la balle qui tourne et s'y arrête.
 */
class RouletteService
{
    public const COLUMNS = 4;
    public const ROWS = 3;

    // Case du plateau (ligne, colonne) -> case de la roue (0..11), comme TABLE_POSITIONS du script de FL
    public const TABLE_POSITIONS = [
        [1, 10, 7, 4],
        [5, 2, 11, 8],
        [9, 6, 3, 0],
    ];

    public function __construct(private GameCornerService $corner)
    {
    }

    private function key(Character $character): string
    {
        return "roulette:{$character->id}";
    }

    /** S'asseoir à une table (mise 1 ou 3) : un plateau vide */
    public function open(Character $character, int $wager): array
    {
        $this->corner->requireCoinCase($character);
        $wager = in_array($wager, [1, 3], true) ? $wager : 1;
        if ($character->coins < $wager) {
            throw new InvalidAction('Vous n\'avez pas assez de jetons pour jouer !');
        }
        $session = ['wager' => $wager, 'played' => array_fill(0, self::COLUMNS * self::ROWS, false)];
        Cache::put($this->key($character), $session, now()->addHours(3));

        return ['wager' => $wager, 'played' => $session['played'], 'coins' => (int) $character->coins,
            'rounds' => $this->rounds(), 'max' => $this->corner->max()];
    }

    private function rounds(): int
    {
        return max(1, min(11, (int) config('game_corner.roulette.rounds', 6)));
    }

    /**
     * Un lancer. $x, $y : la position du curseur sur le plateau (x 0..4, y 0..3, comme le script de FL).
     * Renvoie la case tirée (index 0..11 du plateau), gagné ou non, le gain, les jetons et le plateau.
     */
    public function play(Character $character, int $x, int $y): array
    {
        $session = Cache::get($this->key($character));
        if (! $session) {
            throw new InvalidAction('Asseyez-vous d\'abord à une table.');
        }
        $bet = $this->bet($x, $y);
        $multiplier = $this->multiplier($session['played'], $bet);
        if ($multiplier === 0) {
            throw new InvalidAction('Impossible de miser ici.');
        }

        return DB::transaction(function () use ($character, $session, $bet, $multiplier) {
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $wager = (int) $session['wager'];
            if (! $this->corner->spendCoins($character, $wager)) {
                throw new InvalidAction('Vous n\'avez pas assez de jetons pour jouer !');
            }

            // La balle tombe sur une case encore libre. Gagné ou perdu d'abord : chance = rendement / multiplicateur
            // (sinon, en fin de plateau, miser rapporterait plus que la mise), puis une case libre qui correspond.
            $free = array_keys(array_filter($session['played'], fn ($p) => ! $p));
            $freeBet = array_values(array_intersect($free, $bet));
            $freeOther = array_values(array_diff($free, $bet));
            $chance = (float) config('game_corner.roulette.payout_rate', 0.9) / $multiplier;
            $win = ! $freeOther || ($freeBet && random_int(0, 999999) < $chance * 1000000);
            $pool = $win ? $freeBet : $freeOther;
            $result = $pool[random_int(0, count($pool) - 1)];
            $payout = $win ? $wager * $multiplier : 0;
            $this->corner->addCoins($character, $payout);

            $session['played'][$result] = true;
            $cleared = count(array_filter($session['played'])) >= $this->rounds();
            if ($cleared) {
                $session['played'] = array_fill(0, self::COLUMNS * self::ROWS, false);
            }
            Cache::put($this->key($character), $session, now()->addHours(3));

            return [
                'result' => $result,                                   // case du plateau (ligne * 4 + colonne)
                'slot' => self::TABLE_POSITIONS[intdiv($result, self::COLUMNS)][$result % self::COLUMNS],   // case de la roue
                'win' => $win,
                'multiplier' => $multiplier,
                'payout' => $payout,
                'jackpot' => $win && $multiplier === 12,
                'cleared' => $cleared,
                'played' => $session['played'],
                'coins' => (int) $character->coins,
            ];
        });
    }

    /** Les cases du plateau (index ligne * 4 + colonne) couvertes par la mise */
    public function bet(int $x, int $y): array
    {
        if ($x < 0 || $x > self::COLUMNS || $y < 0 || $y > self::ROWS || ($x === 0 && $y === 0)) {
            throw new InvalidAction('Impossible de miser ici.');
        }
        if ($x === 0) {
            return array_map(fn ($c) => ($y - 1) * self::COLUMNS + $c, range(0, self::COLUMNS - 1));   // une couleur
        }
        if ($y === 0) {
            return array_map(fn ($r) => $r * self::COLUMNS + ($x - 1), range(0, self::ROWS - 1));     // un Pokémon
        }

        return [($y - 1) * self::COLUMNS + ($x - 1)];                                                  // une case
    }

    /** Le multiplicateur : 12 / cases encore libres de la mise (0 si elles sont toutes sorties) */
    public function multiplier(array $played, array $bet): int
    {
        $free = count(array_filter($bet, fn ($i) => ! $played[$i]));

        return $free === 0 ? 0 : intdiv(self::COLUMNS * self::ROWS, $free);
    }
}
