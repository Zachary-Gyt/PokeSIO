<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use App\Scenario\ScenarioRepository;
use App\Scenario\ScriptError;
use App\Scenario\ScriptParser;
use App\Services\AudioLibrary;
use App\Services\MapInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Étape 24 : VÉRIFIE les fichiers de scénario (resources/scenario/*.txt) et les cartes Tiled.
 *
 *   php artisan game:scenario
 *
 * Erreurs (en rouge) : une ligne mal écrite, un objet / Pokémon / dresseur / carte qui n'existe pas,
 * une propriété "event" de Tiled qui vise un événement inexistant, une condition show_if / hide_if mal écrite.
 * Avertissements (en jaune) : un événement jamais utilisé, un PNJ nommé dans un script mais absent de la carte...
 *
 * À lancer après chaque modification : un événement qui contient une erreur n'est PAS joué dans le jeu.
 */
class CheckScenario extends Command
{
    protected $signature = 'game:scenario';

    protected $description = 'Vérifie les fichiers de scénario (resources/scenario) et les événements des cartes Tiled';

    private int $errors = 0;

    private int $warnings = 0;

    public function handle(ScenarioRepository $repository): int
    {
        $files = $repository->files();
        $maps = collect(File::glob(public_path('game/maps') . '/*.tmj'))->map(fn ($f) => basename($f, '.tmj'))->all();
        $this->line('Fichiers de scénario : ' . (count($files) ? implode(', ', array_map(fn ($f) => "{$f}.txt", array_keys($files))) : 'aucun') . '.');

        // 1) Chaque fichier : syntaxe, puis ce qu'il utilise
        $parsed = [];
        foreach ($files as $name => $path) {
            $result = (new ScriptParser)->parse(File::get($path));
            $parsed[$name] = $result;
            foreach ($result['errors'] as $error) {
                $this->problem("{$name}.txt, {$error}");
            }
            if ($name !== ScenarioRepository::COMMON && ! in_array($name, $maps, true)) {
                $this->warn("  ! {$name}.txt : aucune carte ne s'appelle \"{$name}\" (le fichier doit porter le nom de la carte, ou commun.txt).");
                $this->warnings++;
            }
            foreach ($result['events'] as $event => $info) {
                $this->checkCode($name, $event, $info['code'], $maps);
            }
        }

        // 2) Chaque carte : ses objets liés au scénario
        $used = [];
        foreach ($maps as $mapKey) {
            $map = MapInfo::load($mapKey);
            $names = $this->objectNames($mapKey);
            foreach ($map?->eventObjects() ?? [] as $object) {
                $where = "{$mapKey}.tmj, objet n° {$object['id']}" . ($object['name'] ? " ({$object['name']})" : '');
                if ($object['event'] !== null) {
                    $file = isset($parsed[$mapKey]['events'][$object['event']]) ? $mapKey : (isset($parsed[ScenarioRepository::COMMON]['events'][$object['event']]) ? ScenarioRepository::COMMON : null);
                    if ($file === null) {
                        $this->problem("{$where} : l'événement \"{$object['event']}\" n'existe ni dans {$mapKey}.txt ni dans commun.txt.");
                    } else {
                        $used["{$file}:{$object['event']}"] = true;
                        $this->checkTargets($mapKey, $object['event'], $parsed[$file]['events'][$object['event']]['code'], $names);
                    }
                }
                foreach (['show_if', 'hide_if'] as $property) {
                    if ($object[$property] === null) {
                        continue;
                    }
                    try {
                        (new ScriptParser)->parseCondition($object[$property]);
                    } catch (ScriptError $e) {
                        $this->problem("{$where} : {$property} = \"{$object[$property]}\" : {$e->getMessage()}");
                    }
                }
            }
        }

        // 3) Les événements que rien ne déclenche
        foreach ($parsed as $file => $result) {
            if ($file === ScenarioRepository::COMMON) {
                continue;
            }
            foreach (array_keys($result['events']) as $event) {
                if (! isset($used["{$file}:{$event}"])) {
                    $this->warn("  ! {$file}.txt : l'événement \"{$event}\" n'est utilisé par aucun objet de la carte (propriété event).");
                    $this->warnings++;
                }
            }
        }

        $this->newLine();
        if ($this->errors === 0) {
            $this->info("Tout est bon ! ({$this->warnings} avertissement(s))");

            return self::SUCCESS;
        }
        $this->error("{$this->errors} erreur(s), {$this->warnings} avertissement(s). Les événements qui ont une erreur ne seront pas joués.");

        return self::FAILURE;
    }

    /** Les objets, Pokémon, dresseurs, cartes et sons utilisés par un événement existent-ils ? */
    private function checkCode(string $file, string $event, array $code, array $maps): void
    {
        static $music = null;
        $music ??= app(AudioLibrary::class)->files();

        foreach ($code as $ins) {
            $line = end($ins);
            $where = "{$file}.txt, ligne {$line} (événement {$event})";
            $items = [];
            $species = [];
            $trainers = [];
            switch ($ins[0]) {
                case 'give_item':
                case 'take_item':
                    $items[] = $ins[1];
                    break;
                case 'give_pokemon':
                    $species[] = $ins[1];
                    if ($ins[4]) {
                        $items[] = $ins[4];
                    }
                    break;
                case 'give_egg':
                case 'battle_wild':
                case 'cry':
                    $species[] = $ins[1];
                    break;
                case 'battle_trainer':
                    $trainers[] = $ins[1];
                    break;
                case 'teleport':
                    if (! in_array($ins[1], $maps, true)) {
                        $this->problem("{$where} : la carte \"{$ins[1]}\" n'existe pas.");
                    } elseif (($map = MapInfo::load($ins[1])) && ($ins[2] >= $map->width() || $ins[3] >= $map->height())) {
                        $this->problem("{$where} : la case ({$ins[2]}, {$ins[3]}) est en dehors de la carte {$ins[1]}.");
                    }
                    break;
                case 'jz':
                    foreach ($ins[1] as [, $type, $args]) {
                        match ($type) {
                            'item' => $items[] = $args[0],
                            'pokemon' => $species[] = $args[0],
                            'trainer' => $trainers[] = $args[0],
                            default => null,
                        };
                    }
                    break;
                case 'client':
                    $step = $ins[1];
                    if ($step['type'] === 'music' && $step['name'] && ! isset($music['bgm'][mb_strtolower($step['name'])])) {
                        $this->warn("  ! {$where} : musique \"{$step['name']}\" introuvable dans public/game/audio/bgm.");
                        $this->warnings++;
                    }
                    break;
            }
            foreach ($items as $slug) {
                if (! Item::where('slug', $slug)->exists()) {
                    $this->problem("{$where} : l'objet \"{$slug}\" n'existe pas (table items).");
                }
            }
            foreach ($species as $slug) {
                if ($slug === 'legendaire') {   // un légendaire tiré au hasard : il en faut au moins un
                    if (! PokemonSpecies::where('is_legendary', true)->where('is_obtainable', true)->exists()) {
                        $this->problem("{$where} : aucun Pokémon légendaire dans ta base (colonne is_legendary).");
                    }
                    continue;
                }
                if (! PokemonSpecies::where('slug', $slug)->exists()) {
                    $this->problem("{$where} : le Pokémon \"{$slug}\" n'existe pas (table pokemon_species).");
                }
            }
            foreach ($trainers as $slug) {
                if (! Trainer::where('slug', $slug)->exists()) {
                    $this->problem("{$where} : le dresseur \"{$slug}\" n'existe pas (table trainers : as-tu lancé son seeder ?).");
                }
            }
        }
    }

    /** Les PNJ nommés dans les déplacements existent-ils sur la carte ? */
    private function checkTargets(string $mapKey, string $event, array $code, array $names): void
    {
        foreach ($code as $ins) {
            if ($ins[0] !== 'client' || ! in_array($ins[1]['type'], ['move', 'approach', 'face', 'exclaim', 'hide', 'show'], true)) {
                continue;
            }
            foreach (array_filter([$ins[1]['target'] ?? null, $ins[1]['to'] ?? null, $ins[1]['toward'] ?? null]) as $target) {
                if (! in_array($target, ['joueur', 'ce'], true) && ! in_array($target, $names, true)) {
                    $this->warn("  ! {$mapKey}.tmj, événement {$event} (ligne " . end($ins) . ") : aucun PNJ ne s'appelle \"{$target}\" sur cette carte (champ Nom de l'objet dans Tiled).");
                    $this->warnings++;
                }
            }
        }
    }

    /** Les noms des objets du calque "npcs" d'une carte */
    private function objectNames(string $mapKey): array
    {
        $data = json_decode(File::get(public_path("game/maps/{$mapKey}.tmj")), true);
        $names = [];
        foreach ($data['layers'] ?? [] as $layer) {
            if (($layer['type'] ?? '') === 'objectgroup' && $layer['name'] === 'npcs') {
                foreach ($layer['objects'] ?? [] as $o) {
                    if (($o['name'] ?? '') !== '') {
                        $names[] = $o['name'];
                    }
                }
            }
        }

        return $names;
    }

    private function problem(string $message): void
    {
        $this->line("  <fg=red>✗</> {$message}");
        $this->errors++;
    }
}
