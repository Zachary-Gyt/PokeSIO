<?php

namespace App\Scenario;

use App\Battle\BattlePresenter;
use App\Battle\InvalidAction;
use App\Models\Battle;
use App\Models\Character;
use App\Models\Item;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use App\Services\BagService;
use App\Services\BattleService;
use App\Services\MapInfo;
use App\Services\PokemonFactory;
use App\Services\PokemonPresenter;
use Illuminate\Support\Facades\Log;

/**
 * Étape 24 : JOUE un événement du scénario, côté serveur.
 *
 * Le serveur exécute les instructions une par une (ScriptParser les a préparées) :
 *   - ce qui change la partie (objets, Pokémon, argent, drapeaux, soin, combats) est fait ICI :
 *     le navigateur ne peut rien inventer ;
 *   - ce qui se voit ou s'entend (textes, déplacements, musique...) est mis dans une liste d'ÉTAPES
 *     que le navigateur joue dans l'ordre (EventRunner.js).
 *
 * Quand il faut attendre le joueur (une question, un menu, un combat), le serveur s'arrête, retient
 * où il en est (story_flags['scenario']['run']) et renvoie ce qu'il attend. Le navigateur reprend
 * ensuite l'événement avec resume() : la réponse choisie, ou "le combat est fini".
 *
 * Réponse (start et resume) :
 *   ['steps' => [...], 'wait' => null | ['type' => 'question'|'menu'|'battle', ...], 'done' => bool, 'hidden' => [ids]]
 */
class EventRunner
{
    /** Au plus ce nombre d'instructions par appel (sécurité : un script ne peut pas tourner sans fin) */
    private const MAX_INSTRUCTIONS = 2000;

    private array $steps = [];

    public function __construct(
        private ScenarioRepository $scenarios,
        private BattleService $battles,
        private PokemonFactory $pokemonFactory,
    ) {
    }

    /** Lance l'événement de l'objet Tiled $objectId de la carte. Lance InvalidAction si c'est impossible. */
    public function start(Character $character, string $mapKey, int $objectId): array
    {
        $map = MapInfo::load($mapKey);
        $object = $map?->eventObject($objectId);
        if (! $object || ! $object['event']) {
            throw new InvalidAction('Il n\'y a pas d\'événement ici.');
        }
        if (in_array($objectId, $this->hiddenObjects($character, $map), true)) {
            throw new InvalidAction('Cet événement n\'est pas disponible.');
        }
        $event = $this->scenarios->find($mapKey, $object['event']);
        if (! $event) {
            Log::warning("Scénario : événement \"{$object['event']}\" introuvable (carte {$mapKey}, objet {$objectId}).");

            return $this->error($character, $map, "l'événement \"{$object['event']}\" n'existe pas dans resources/scenario/{$mapKey}.txt (ou il contient une erreur : php artisan scenario:verifier).");
        }

        // Un événement commencé et jamais fini (page fermée pendant une question...) est oublié
        $run = [
            'map' => $mapKey, 'event' => $event['name'], 'file' => $event['file'], 'pc' => 0,
            'speaker' => $object['speaker'] ?: null, 'choice' => null, 'result' => null, 'wait' => null, 'battle' => null,
        ];

        return $this->execute($character, $map, $run, $event['code']);
    }

    /** Reprend l'événement en attente. $choice : réponse à une question (0 = Oui, 1 = Non) ou à un menu. */
    public function resume(Character $character, ?int $choice): array
    {
        $run = StoryState::run($character);
        if (! $run || ! $run['wait']) {
            throw new InvalidAction('Aucun événement en cours.');
        }
        $map = MapInfo::load($run['map']);
        $event = $this->scenarios->find($run['map'], $run['event']);
        if (! $map || ! $event) {
            StoryState::setRun($character, null);
            $character->save();
            throw new InvalidAction('Cet événement n\'existe plus.');
        }

        if ($run['wait'] === 'battle') {
            $battle = Battle::where('character_id', $character->id)->find($run['battle']);
            if ($battle && $battle->status === 'active') {
                throw new InvalidAction('Le combat n\'est pas fini.');
            }
            $run['result'] = $battle?->result;
            // Combat perdu (sans "perdable") : tout est devenu noir, l'événement s'arrête là
            if ($run['result'] === 'loss' && empty($battle?->state['canLose'])) {
                StoryState::setRun($character, null);
                $character->save();

                return ['steps' => [], 'wait' => null, 'done' => true, 'hidden' => $this->hiddenObjects($character, $map)];
            }
        } else {
            $count = $run['wait'] === 'question' ? 2 : (int) ($run['options'] ?? 0);
            if ($choice === null || $choice < 0 || $choice >= $count) {
                throw new InvalidAction('Réponse invalide.');
            }
            $run['choice'] = $choice;
        }
        $run['wait'] = null;

        return $this->execute($character, $map, $run, $event['code']);
    }

    // =====================================================================
    // Exécution
    // =====================================================================

    private function execute(Character $character, MapInfo $map, array $run, array $code): array
    {
        $this->steps = [];
        $wait = null;
        $done = false;

        try {
            for ($n = 0; ; $n++) {
                if ($n >= self::MAX_INSTRUCTIONS) {
                    throw new ScriptError('trop d\'instructions d\'un coup (boucle ?).');
                }
                if ($run['pc'] >= count($code)) {
                    $done = true;
                    break;
                }
                $ins = $code[$run['pc']];
                $run['pc']++;
                $run['line'] = end($ins);

                $wait = $this->instruction($character, $map, $run, $ins);
                if ($wait === 'stop') {
                    $wait = null;
                    $done = true;
                    break;
                }
                if ($wait !== null) {
                    break;
                }
            }
        } catch (ScriptError $e) {
            Log::warning("Scénario {$run['file']}.txt, événement {$run['event']}, ligne " . ($run['line'] ?? '?') . " : {$e->getMessage()}");
            $this->steps[] = ['type' => 'say', 'name' => '', 'text' => "Erreur dans le scénario ({$run['file']}.txt, ligne " . ($run['line'] ?? '?') . ") : {$e->getMessage()}"];
            $done = true;
            $wait = null;
        }

        StoryState::setRun($character, $done ? null : $run);
        $character->save();

        return [
            'steps' => $this->steps,
            'wait' => $wait,
            'done' => $done,
            'hidden' => $this->hiddenObjects($character->fresh(), $map),
            'money' => $character->fresh()->money,
        ];
    }

    /**
     * Une instruction. Renvoie null (on continue), 'stop' (fin), ou ce que le navigateur doit attendre.
     */
    private function instruction(Character $character, MapInfo $map, array &$run, array $ins): mixed
    {
        switch ($ins[0]) {
            case 'jmp':
                $run['pc'] = $ins[1];

                return null;
            case 'jz':
                if (! Conditions::check($ins[1], $character, $run)) {
                    $run['pc'] = $ins[2];
                }

                return null;
            case 'say':
                $this->steps[] = ['type' => 'say', 'name' => $ins[2] ? '' : (string) ($run['speaker'] ?? ''), 'text' => $this->text($character, $ins[1])];

                return null;
            case 'speaker':
                $run['speaker'] = $ins[1];

                return null;
            case 'ask':
                $run['wait'] = 'question';
                $run['choice'] = null;

                return ['type' => 'question', 'name' => (string) ($run['speaker'] ?? ''), 'text' => $this->text($character, $ins[1])];
            case 'menu':
                $run['wait'] = 'menu';
                $run['choice'] = null;
                $run['options'] = count($ins[2]);

                return ['type' => 'menu', 'name' => (string) ($run['speaker'] ?? ''), 'text' => $this->text($character, $ins[1]),
                    'options' => array_map(fn ($o) => $this->text($character, $o), $ins[2])];
            case 'flag':
                StoryState::setFlag($character, $ins[1], $ins[2]);

                return null;
            case 'var':
                $value = StoryState::var($character, $ins[1]);
                StoryState::setVar($character, $ins[1], match ($ins[2]) {
                    '+' => $value + $ins[3],
                    '-' => $value - $ins[3],
                    default => $ins[3],
                });

                return null;
            case 'give_item':
                $this->giveItem($character, $ins[1], $ins[2]);

                return null;
            case 'take_item':
                $this->takeItem($character, $ins[1], $ins[2]);

                return null;
            case 'give_money':
                $character->addMoney($ins[1]);
                $this->steps[] = ['type' => 'say', 'name' => '', 'me' => 'item', 'text' => "{$character->name} reçoit " . self::money($ins[1]) . ' !'];

                return null;
            case 'take_money':
                $paid = min($character->money, $ins[1]);
                $character->spendMoney($paid);
                $this->steps[] = ['type' => 'say', 'name' => '', 'text' => "{$character->name} donne " . self::money($paid) . '.'];

                return null;
            case 'give_pokemon':
                $this->givePokemon($character, $map, $ins[1], $ins[2], $ins[3], $ins[4]);

                return null;
            case 'give_egg':
                $this->giveEgg($character, $map, $ins[1], $ins[2]);

                return null;
            case 'heal':
                $character->healParty();
                $this->steps[] = ['type' => 'me', 'name' => 'heal', 'wait' => true];
                $this->steps[] = ['type' => 'party'];   // le Pokémon qui suit peut changer (il n'est plus K.O.)

                return null;
            case 'battle_trainer':
                return $this->trainerBattle($character, $run, $ins[1], $ins[2]);
            case 'battle_wild':
                return $this->wildBattle($character, $run, $ins[1], $ins[2], $ins[3], $ins[4]);
            case 'teleport':
                $this->teleport($character, $ins[1], $ins[2], $ins[3], $ins[4]);

                return 'stop';
            case 'cry':
                $url = PokemonSpecies::assetUrl(PokemonSpecies::where('slug', $ins[1])->value('cry'));
                if ($url) {
                    $this->steps[] = ['type' => 'cry', 'url' => $url];
                }

                return null;
            case 'client':
                $this->steps[] = $ins[1];

                return null;
            case 'stop':
                return 'stop';
        }

        throw new ScriptError("instruction inconnue : {$ins[0]}.");
    }

    // =====================================================================
    // Les actions
    // =====================================================================

    private function giveItem(Character $character, string $slug, int $quantity): void
    {
        $item = Item::where('slug', $slug)->first() ?? throw new ScriptError("objet inconnu : \"{$slug}\".");
        $character->addItem($item, $quantity);
        $who = $character->name;
        $what = $quantity > 1 ? "{$quantity} {$item->name}" : $item->name;
        $pocket = BagService::POCKETS[BagService::POCKET_OF[$item->pocket] ?? 1] ?? 'Objets';
        $this->steps[] = ['type' => 'say', 'name' => '', 'me' => $item->pocket === 'key_items' ? 'key_item' : 'item', 'text' => "{$who} obtient {$what} !"];
        $this->steps[] = ['type' => 'say', 'name' => '', 'text' => "{$who} range l'objet dans la poche {$pocket}."];
        if ($item->effect_code === 'ride') {
            $this->steps[] = ['type' => 'bike'];   // la Bicyclette : la touche B marche tout de suite
        }
    }

    private function takeItem(Character $character, string $slug, int $quantity): void
    {
        $item = Item::where('slug', $slug)->first() ?? throw new ScriptError("objet inconnu : \"{$slug}\".");
        $quantity = min($quantity, $character->quantityOf($item));
        if ($quantity <= 0) {
            return;   // rien à prendre : vérifie d'abord avec « si objet ... »
        }
        $character->removeItem($item, $quantity);
        $what = $quantity > 1 ? "{$quantity} {$item->name}" : $item->name;
        $this->steps[] = ['type' => 'say', 'name' => '', 'text' => "{$character->name} donne {$what}."];
    }

    /** L'espèce d'un « donner » : son slug, ou « legendaire » = un légendaire ou fabuleux de la base, au hasard */
    private static function species(string $slug): PokemonSpecies
    {
        if ($slug === 'legendaire') {
            return PokemonSpecies::where('is_legendary', true)->where('is_obtainable', true)->inRandomOrder()->first()
                ?? throw new ScriptError('aucun Pokémon légendaire dans la base.');
        }

        return PokemonSpecies::where('slug', $slug)->first() ?? throw new ScriptError("Pokémon inconnu : \"{$slug}\".");
    }

    /** Un Œuf (qui éclot après $steps pas, dans l'équipe) */
    private function giveEgg(Character $character, MapInfo $map, string $slug, int $steps): void
    {
        $species = self::species($slug);
        $egg = $this->pokemonFactory->make($species, 1);
        $egg->fill([
            'is_egg' => true, 'egg_steps' => $steps, 'current_hp' => 0, 'experience' => 0,
            'friendship' => $species->base_friendship ?? 120, 'met_level' => null,
        ]);
        $scenario = $character->story_flags['scenario'] ?? [];
        $given = $this->pokemonFactory->giveTo($character, $egg, ['location' => $map->property('displayName')]);
        $flags = $character->fresh()->story_flags ?? [];
        $flags['scenario'] = $scenario;
        $character->story_flags = $flags;
        if (! $given) {
            $this->steps[] = ['type' => 'say', 'name' => '', 'text' => 'Votre équipe et votre PC sont pleins ! L\'Œuf ne peut pas être reçu.'];

            return;
        }
        $this->steps[] = ['type' => 'say', 'name' => '', 'me' => 'pokemon_get', 'text' => "{$character->name} reçoit un Œuf !"];
        if ($given->location !== 'party') {
            $this->steps[] = ['type' => 'say', 'name' => '', 'text' => 'L\'Œuf a été envoyé au PC. Il n\'éclora que dans l\'équipe !'];
        }
        $this->steps[] = ['type' => 'party'];
    }

    private function givePokemon(Character $character, MapInfo $map, string $slug, int $level, bool $shiny, ?string $itemSlug): void
    {
        $species = self::species($slug);
        $item = $itemSlug ? (Item::where('slug', $itemSlug)->first() ?? throw new ScriptError("objet inconnu : \"{$itemSlug}\".")) : null;

        $pokemon = $this->pokemonFactory->make($species, $level, $shiny ? ['shiny' => true] : []);
        if ($item) {
            $pokemon->held_item_id = $item->id;
        }
        $scenario = $character->story_flags['scenario'] ?? [];
        $given = $this->pokemonFactory->giveTo($character, $pokemon, ['location' => $map->property('displayName')]);
        // giveTo a pu écrire dans story_flags (Pokédex) : on reprend sa version, avec notre scénario
        $flags = $character->fresh()->story_flags ?? [];
        $flags['scenario'] = $scenario;
        $character->story_flags = $flags;

        if (! $given) {
            $this->steps[] = ['type' => 'say', 'name' => '', 'text' => 'Votre équipe et votre PC sont pleins ! Le Pokémon ne peut pas être reçu.'];

            return;
        }
        $this->steps[] = ['type' => 'say', 'name' => '', 'me' => 'pokemon_get', 'text' => "{$character->name} reçoit {$species->name} !"];
        if ($given->location !== 'party') {
            $this->steps[] = ['type' => 'say', 'name' => '', 'text' => "{$species->name} a été envoyé au PC."];
        }
        $this->steps[] = ['type' => 'nickname', 'pokemon' => PokemonPresenter::detail($given->fresh())];
        $this->steps[] = ['type' => 'party'];
    }

    private function trainerBattle(Character $character, array &$run, string $slug, bool $canLose): ?array
    {
        $trainer = Trainer::where('slug', $slug)->first() ?? throw new ScriptError("dresseur inconnu : \"{$slug}\".");
        try {
            $battle = $this->battles->startTrainer($character, $trainer, ['canLose' => $canLose]);
        } catch (InvalidAction $e) {
            // Déjà battu, aucun Pokémon en forme... : on le dit, et l'événement continue (sans résultat)
            $this->steps[] = ['type' => 'say', 'name' => '', 'text' => $e->getMessage()];
            $run['result'] = null;

            return null;
        }

        return $this->waitBattle($run, $battle, ['trainer' => $trainer->slug]);
    }

    private function wildBattle(Character $character, array &$run, string $slug, int $level, bool $shiny, bool $canLose): ?array
    {
        $species = PokemonSpecies::where('slug', $slug)->first() ?? throw new ScriptError("Pokémon inconnu : \"{$slug}\".");
        try {
            $battle = $this->battles->startSpawn($character, $species, $level, $shiny, ['canLose' => $canLose]);
        } catch (InvalidAction $e) {
            $this->steps[] = ['type' => 'say', 'name' => '', 'text' => $e->getMessage()];
            $run['result'] = null;

            return null;
        }

        return $this->waitBattle($run, $battle);
    }

    /** Le combat est prêt : le navigateur le joue, puis reprendra l'événement */
    private function waitBattle(array &$run, Battle $battle, array $extra = []): array
    {
        $run['wait'] = 'battle';
        $run['battle'] = $battle->id;
        $run['result'] = null;
        $intro = $battle->turn === 0 ? ($battle->log[0]['events'] ?? []) : [];

        return ['type' => 'battle', 'events' => BattlePresenter::events($intro), 'battle' => $this->battles->view($battle)] + $extra;
    }

    private function teleport(Character $character, string $mapKey, int $x, int $y, string $facing): void
    {
        $map = MapInfo::load($mapKey) ?? throw new ScriptError("carte inconnue : \"{$mapKey}\".");
        if ($x >= $map->width() || $y >= $map->height()) {
            throw new ScriptError("la case ({$x}, {$y}) est en dehors de la carte {$mapKey}.");
        }
        $character->forceFill(['current_map' => $mapKey, 'position_x' => $x, 'position_y' => $y, 'facing' => $facing]);
        $this->steps[] = ['type' => 'teleport', 'map' => $mapKey, 'x' => $x, 'y' => $y, 'facing' => $facing];
    }

    // =====================================================================
    // Visibilité des objets Tiled (show_if / hide_if)
    // =====================================================================

    /** Les numéros des objets Tiled de la carte qui sont cachés pour ce personnage */
    public function hiddenObjects(Character $character, MapInfo $map): array
    {
        $hidden = [];
        foreach ($map->eventObjects() as $id => $object) {
            $show = $object['show_if'] !== null ? self::condition($object['show_if']) : null;
            $hide = $object['hide_if'] !== null ? self::condition($object['hide_if']) : null;
            if (($show !== null && ! Conditions::check($show, $character)) || ($hide !== null && Conditions::check($hide, $character))) {
                $hidden[] = $id;
            }
        }

        return $hidden;
    }

    /** Une condition de propriété Tiled, lue une seule fois. Mal écrite : null (ignorée ; scenario:verifier la signale). */
    private static function condition(string $text): ?array
    {
        static $cache = [];
        if (! array_key_exists($text, $cache)) {
            try {
                $cache[$text] = (new ScriptParser)->parseCondition($text);
            } catch (ScriptError) {
                $cache[$text] = null;
            }
        }

        return $cache[$text];
    }

    // =====================================================================
    // Outils
    // =====================================================================

    /** Remplace {joueur}, {argent}, {badges} et {variable:nom} dans un texte */
    private function text(Character $character, string $text): string
    {
        return preg_replace_callback('/\{([a-z]+)(?::([a-z0-9_]+))?\}/iu', function ($m) use ($character) {
            return match (ScriptParser::norm($m[1])) {
                'joueur' => $character->name,
                'argent' => self::money($character->money),
                'badges' => (string) $character->badges()->count(),
                'variable' => (string) StoryState::var($character, ScriptParser::norm($m[2] ?? '')),
                default => $m[0],
            };
        }, $text);
    }

    private static function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ') . ' ₽';
    }

    private function error(Character $character, MapInfo $map, string $message): array
    {
        return [
            'steps' => [['type' => 'say', 'name' => '', 'text' => "Erreur dans le scénario : {$message}"]],
            'wait' => null, 'done' => true,
            'hidden' => $this->hiddenObjects($character, $map),
            'money' => $character->money,
        ];
    }
}
