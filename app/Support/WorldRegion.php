<?php

namespace App\Support;

use App\Models\Badge;
use App\Scenario\ScriptParser;
use App\Services\MapInfo;
use Illuminate\Support\Facades\File;

/**
 * Le MONDE DU JEU pour le Wiki : Crémieu, la Route 1, Bachut, l'École ORT et le Parc Safari.
 * Les cartes de démonstration et de test (bourg_demo, arene_test, dev_room, la Ligue de démo...) ne sont pas
 * citées comme lieux d'obtention.
 *
 * Pour ajouter une nouvelle zone au monde : ajoute le début du nom de ses cartes dans PREFIXES.
 */
class WorldRegion
{
    /** Début du nom (clé) des cartes du monde */
    public const PREFIXES = ['cremieu', 'road_1', 'bachut', 'ort_', 'safari_'];

    /** Générations d'objets montrées dans le Wiki (les autres seulement si on peut les obtenir en jeu) */
    public const MAX_ITEM_GENERATION = 4;

    private static ?array $generations = null;

    public static function contains(string $mapKey): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($mapKey, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Les clés des cartes du monde (fichiers public/game/maps/*.tmj) */
    public static function maps(): array
    {
        return collect(glob(public_path('game/maps') . '/*.tmj') ?: [])
            ->map(fn ($f) => basename($f, '.tmj'))->filter(fn ($k) => self::contains($k))->values()->all();
    }

    /** Le nom affiché d'une carte (propriété displayName), ou sa clé */
    public static function name(string $mapKey): string
    {
        return MapInfo::load($mapKey)?->property('displayName') ?? $mapKey;
    }

    /**
     * La génération d'apparition d'un objet (resources/data/item_generations.json, tiré de PokéAPI),
     * ou null s'il est apparu après la 4e génération.
     */
    public static function itemGeneration(string $slug): ?int
    {
        self::$generations ??= json_decode(File::get(resource_path('data/item_generations.json')), true) ?: [];

        return self::$generations[$slug] ?? null;
    }

    /**
     * Les objets donnés par les SCÉNARIOS des cartes du monde (resources/scenario/<carte>.txt, « donner objet ») :
     * [['item' => slug, 'who' => 'Professeur Thouverez', 'map' => 'Laboratoire', 'buy' => bool, 'condition' => 'Badge Roche' | null]]
     */
    public static function scenarioGifts(): array
    {
        $dir = config('game.scenario_path', resource_path('scenario'));
        $badges = Badge::pluck('name', 'order');
        $gifts = [];
        foreach (self::maps() as $key) {
            $file = "{$dir}/{$key}.txt";
            if (! File::exists($file)) {
                continue;
            }
            $events = (new ScriptParser)->parse(File::get($file))['events'];
            foreach ($events as $event) {
                $code = $event['code'];
                $speaker = null;
                $paid = false;
                foreach ($code as $i => $ins) {
                    if ($ins[0] === 'speaker') {
                        $speaker = $ins[1];
                    }
                    if (in_array($ins[0], ['take_money', 'take_item'], true)) {
                        $paid = true;
                    }
                    if ($ins[0] !== 'give_item') {
                        continue;
                    }
                    // Les conditions des « si » qui entourent ce « donner » (badge n, badges n)
                    $conditions = [];
                    foreach ($code as $j => $test) {
                        if ($j >= $i || $test[0] !== 'jz' || ($test[2] ?? 0) <= $i) {
                            continue;
                        }
                        foreach ($test[1] as [$negated, $name, $args]) {
                            if ($negated) {
                                continue;
                            }
                            if ($name === 'badge') {
                                $conditions[] = $badges[$args[0]] ?? "Badge n° {$args[0]}";
                            } elseif ($name === 'badges') {
                                $conditions[] = "{$args[0]} badges";
                            }
                        }
                    }
                    $gifts[] = ['item' => $ins[1], 'who' => $speaker ?? 'un PNJ', 'map' => self::name($key), 'buy' => $paid,
                        'condition' => $conditions ? implode(', ', array_unique($conditions)) : null];
                }
            }
        }

        return $gifts;
    }
}
