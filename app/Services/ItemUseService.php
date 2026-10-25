<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Item;
use App\Models\Pokemon;

/**
 * Utiliser un objet du Sac HORS combat, sur un Pokémon de l'équipe :
 * Potions, Antidote, Rappel, Huile, Super Bonbon, PV Plus, Pierres d'évolution...
 *
 * Renvoie ['messages' => [...], 'consumed' => bool, 'levelUp' => ..., 'learn' => [...], 'evolution' => ...]
 * Lance InvalidAction ("Cela n'aura aucun effet.") si l'objet ne sert à rien : il n'est alors pas consommé.
 */
class ItemUseService
{
    /** PV Plus, Protéine... : la stat augmentée */
    private const VITAMINS = [
        'hp-up' => 'hp', 'protein' => 'attack', 'iron' => 'defense',
        'calcium' => 'sp_attack', 'zinc' => 'sp_defense', 'carbos' => 'speed',
    ];

    private const STAT_NAMES = [
        'hp' => 'Les PV', 'attack' => "L'Attaque", 'defense' => 'La Défense',
        'sp_attack' => "L'Attaque Spéciale", 'sp_defense' => 'La Défense Spéciale', 'speed' => 'La Vitesse',
    ];

    public function __construct(private EvolutionService $evolutions, private FriendshipService $friendship)
    {
    }

    public function use(Character $character, Item $item, Pokemon $pokemon, ?int $moveSlot = null): array
    {
        $pokemon->loadMissing(PokemonPresenter::RELATIONS);
        if ($pokemon->is_egg) {
            throw new InvalidAction('Cela n\'aura aucun effet sur un Œuf.');   // étape 18
        }
        $name = $pokemon->display_name;
        $fainted = $pokemon->current_hp <= 0;
        $max = $pokemon->max_hp;
        $value = (int) $item->effect_value;
        // Étape 22 : les Flûtes (Bleue, Jaune, Rouge...) servent indéfiniment
        $result = ['messages' => [], 'consumed' => ! str_ends_with($item->slug, '-flute')];

        switch ($item->effect_code) {
            case 'heal_hp':
            case 'heal_hp_full':
            case 'heal_hp_percent':
            case 'full_restore':
                $cures = $item->effect_code === 'full_restore' && $pokemon->status !== 'none';
                if ($fainted || ($pokemon->current_hp >= $max && ! $cures)) {
                    $this->noEffect();
                }
                $amount = match ($item->effect_code) {
                    'heal_hp' => $value,
                    'heal_hp_percent' => max(1, intdiv($max * $value, 100)),
                    default => $max,
                };
                $before = $pokemon->current_hp;
                $pokemon->current_hp = min($max, $pokemon->current_hp + $amount);
                if ($cures) {
                    $pokemon->status = 'none';
                    $pokemon->sleep_turns = 0;
                }
                $pokemon->save();
                $healed = $pokemon->current_hp - $before;
                $result['messages'][] = $healed > 0 ? "{$name} récupère {$healed} PV." : "{$name} est guéri.";
                break;

            case 'cure_status':
                $statuses = config("items.cures.{$item->slug}");   // étape 22 : config/items.php
                if ($fainted || $pokemon->status === 'none' || ($statuses && ! in_array($pokemon->status, $statuses, true))) {
                    $this->noEffect();
                }
                $pokemon->forceFill(['status' => 'none', 'sleep_turns' => 0])->save();
                $result['messages'][] = "{$name} est guéri.";
                break;

            case 'revive':
                if (! $fainted) {
                    $this->noEffect();
                }
                $pokemon->forceFill(['current_hp' => max(1, intdiv($max * ($value ?: 50), 100)), 'status' => 'none'])->save();
                $result['messages'][] = "{$name} a repris connaissance !";
                break;

            case 'restore_pp':
            case 'restore_pp_all':
                $moves = $item->effect_code === 'restore_pp'
                    ? $pokemon->moves->where('slot', $moveSlot)
                    : $pokemon->moves;
                $restored = false;
                foreach ($moves as $pm) {
                    if ($pm->current_pp < $pm->max_pp) {
                        $pm->current_pp = min($pm->max_pp, $pm->current_pp + ($value ?: 10));
                        $pm->save();
                        $restored = true;
                    }
                }
                if (! $restored) {
                    $this->noEffect();
                }
                $result['messages'][] = 'Les PP sont restaurés.';
                break;

            case 'pp_up':
                // Étape 22 : PP Plus (+1 palier, 3 au plus) et PP Max (directement 3)
                $pm = $pokemon->moves->firstWhere('slot', $moveSlot);
                if (! $pm || $pm->pp_ups >= 3 || ! $pm->move?->pp || $pm->move->pp <= 1) {
                    $this->noEffect();
                }
                $oldMax = $pm->max_pp;
                $pm->pp_ups = $value >= 3 ? 3 : $pm->pp_ups + 1;
                $pm->current_pp += $pm->max_pp - $oldMax;
                $pm->save();
                $result['messages'][] = "Les PP de {$pm->move->name} augmentent.";
                break;

            case 'ev_down':
                // Étape 22 : Baies Grena, Alga... : -10 EV (le Pokémon les adore : Bonheur +)
                $stat = config("items.ev_berries.{$item->slug}");
                $ev = $stat ? (int) $pokemon->{"ev_{$stat}"} : 0;
                if (! $stat || ($ev <= 0 && $pokemon->friendship >= FriendshipService::MAX)) {
                    $this->noEffect();
                }
                $oldMax = $pokemon->max_hp;
                $pokemon->{"ev_{$stat}"} = max(0, $ev - ($value ?: 10));
                if ($stat === 'hp') {
                    $pokemon->current_hp = max($pokemon->current_hp > 0 ? 1 : 0, min($pokemon->max_hp, $pokemon->current_hp - ($oldMax - $pokemon->max_hp)));
                }
                $this->friendship->change($pokemon, 'evberry');
                $pokemon->save();
                $label = self::STAT_NAMES[$stat];
                $result['messages'][] = $ev > 0
                    ? "{$name} vous aime plus ! {$label} " . ($stat === 'hp' ? 'baissent.' : 'baisse.')
                    : "{$name} vous aime plus ! {$label} ne " . ($stat === 'hp' ? 'peuvent' : 'peut') . ' pas baisser plus.';
                break;

            case 'add_ev':
                $stat = self::VITAMINS[$item->slug] ?? null;
                $ev = $stat ? $pokemon->{"ev_{$stat}"} : 252;
                $room = min(Pokemon::MAX_EV_PER_STAT - $ev, Pokemon::MAX_EV_TOTAL - $pokemon->evTotal());
                if (! $stat || $room <= 0) {
                    $this->noEffect();
                }
                $oldMax = $pokemon->max_hp;
                $pokemon->{"ev_{$stat}"} = $ev + min($value ?: 10, $room);
                if ($stat === 'hp') {
                    $pokemon->current_hp += $pokemon->max_hp - $oldMax;
                }
                $this->friendship->change($pokemon, 'vitamin');   // étape 22
                $pokemon->save();
                $result['messages'][] = self::STAT_NAMES[$stat] . " de {$name} " . ($stat === 'hp' ? 'augmentent.' : 'augmente.');
                break;

            case 'level_up':
                if ($pokemon->level >= Pokemon::MAX_LEVEL) {
                    $this->noEffect();
                }
                $levelUp = $this->levelUp($pokemon);
                $result['messages'] = $levelUp['messages'];
                unset($levelUp['messages']);
                $result += $levelUp;
                break;

            case 'evolve':
                // Pierre : on vérifie qu'elle fait évoluer CE Pokémon. Elle ne sera consommée qu'à
                // l'évolution (le joueur peut encore l'annuler pendant l'animation).
                $evolution = $this->evolutions->candidate($pokemon, $item);
                if (! $evolution) {
                    $this->noEffect();
                }
                $result['consumed'] = false;
                $result['evolution'] = $this->evolutions->preview($pokemon, $evolution, $item);
                break;

            case 'teach':
                // CT / CS : réutilisable, jamais consommée
                $result['consumed'] = false;
                $move = $item->move;
                if (! $move || ! $this->evolutions->canLearnByMachine($pokemon, $move)) {
                    throw new InvalidAction("{$name} ne peut pas apprendre {$move?->name}.");
                }
                if ($pokemon->moves->contains('move_id', $move->id)) {
                    throw new InvalidAction("{$name} connaît déjà {$move->name}.");
                }
                $machine = strtok($item->name, ' ');            // "CT39 Tomberoche" -> "CT39"
                $result['messages'][] = "Vous utilisez la {$machine}.";
                if ($pokemon->moves->count() < 4) {
                    $this->evolutions->learn($pokemon, $move, null, $item);
                    $result['messages'][] = "{$name} apprend {$move->name} !";
                } else {
                    // 4 capacités : le jeu demandera laquelle oublier (puis /equipe/apprendre avec item_id)
                    $result['learn'] = [PokemonPresenter::newMove($move)];
                }
                break;

            default:
                throw new InvalidAction('Impossible d\'utiliser cet objet ici.');
        }

        // Étape 22 : les plantes médicinales sont amères, le Pokémon les aime moins
        if ($bitter = config("items.bitter.{$item->slug}")) {
            $this->friendship->apply($pokemon, $bitter);
        }

        if ($result['consumed']) {
            $character->removeItem($item);
            $result['messages'] = array_merge(["Vous utilisez {$item->name}."], $result['messages']);
        } elseif (str_ends_with($item->slug, '-flute')) {
            $result['messages'] = array_merge(["Vous jouez de la {$item->name}."], $result['messages']);
        }

        return $result;
    }

    /** Super Bonbon : un niveau de plus, puis nouvelles attaques et peut-être une évolution */
    private function levelUp(Pokemon $pokemon): array
    {
        $oldStats = $pokemon->stats();
        $pokemon->level++;
        $this->friendship->change($pokemon, 'levelup');   // étape 22
        $pokemon->experience = max($pokemon->experience, $pokemon->species->experienceForLevel($pokemon->level));
        $newStats = $pokemon->stats();
        if ($pokemon->current_hp > 0) {
            $pokemon->current_hp += $newStats['hp'] - $oldStats['hp'];
        }
        $pokemon->save();

        $messages = ["{$pokemon->display_name} monte au N. {$pokemon->level} !"];
        $moves = $this->evolutions->learnAtCurrentLevel($pokemon);
        foreach ($moves['learned'] as $moveName) {
            $messages[] = "{$pokemon->display_name} apprend {$moveName} !";
        }

        $evolution = $this->evolutions->candidate($pokemon->fresh(PokemonPresenter::RELATIONS));

        return [
            'messages' => $messages,
            'levelUp' => ['oldStats' => $oldStats, 'stats' => $newStats],
            'learn' => $moves['pending'],
            'evolution' => $evolution ? $this->evolutions->preview($pokemon, $evolution) : null,
        ];
    }

    private function noEffect(): never
    {
        throw new InvalidAction('Cela n\'aura aucun effet.');
    }
}
