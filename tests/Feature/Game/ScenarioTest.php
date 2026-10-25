<?php

namespace Tests\Feature\Game;

use App\Models\Battle;
use App\Models\Character;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use App\Scenario\ScriptError;
use App\Scenario\ScriptParser;
use App\Scenario\StoryState;
use App\Services\BattleService;
use App\Services\MapInfo;
use Illuminate\Support\Facades\File;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Étape 24 : les événements du scénario (resources/scenario/*.txt) */
class ScenarioTest extends GameTestCase
{
    private string $scenarioDir;

    protected function setUp(): void
    {
        parent::setUp();
        // Les scénarios des tests sont écrits dans un dossier temporaire (pas dans TES fichiers)
        $this->scenarioDir = "{$this->publicDir}/scenario";
        File::ensureDirectoryExists($this->scenarioDir);
        config(['game.scenario_path' => $this->scenarioDir]);
    }

    /** Une carte "test_ville" avec ces objets, et son fichier de scénario */
    private function world(string $script, array $objects): void
    {
        GameWorld::map('test_ville', $objects);
        File::put("{$this->scenarioDir}/test_ville.txt", $script);
    }

    /** Le numéro d'objet Tiled de l'objet qui joue cet événement */
    private function objectId(string $event): int
    {
        return collect(MapInfo::load('test_ville')->eventObjects())->firstWhere('event', $event)['id'];
    }

    private function start(Character $character, string $event)
    {
        return $this->actingAs($character->user)->postJson("/jouer/{$character->id}/evenements", ['map' => 'test_ville', 'object' => $this->objectId($event)]);
    }

    private function resume(Character $character, ?int $choice = null)
    {
        return $this->actingAs($character->user)->postJson("/jouer/{$character->id}/evenements/suite", $choice === null ? [] : ['choice' => $choice]);
    }

    private static function texts($response): array
    {
        return collect($response->json('steps'))->where('type', 'say')->pluck('text')->all();
    }

    // =====================================================================
    // Le langage
    // =====================================================================

    public function test_the_parser_turns_blocks_into_jumps(): void
    {
        $result = (new ScriptParser)->parse(<<<'TXT'
            # commentaire
            Événement prof
                si drapeau vu
                    dire Re-bonjour !
                sinon si badge 1 et question Une Potion ?
                    donner objet potion 2
                sinon
                    drapeau vu
                fin
            fin
            TXT);

        $this->assertSame([], $result['errors']);
        $code = $result['events']['prof']['code'];
        $this->assertSame(['jz', [[false, 'flag', ['vu']]], 3], array_slice($code[0], 0, 3));
        $this->assertSame('ask', $code[4][0]);
        $this->assertSame(['give_item', 'potion', 2], array_slice($code[6], 0, 3));
        $this->assertSame(['jmp', 9], array_slice($code[2], 0, 2));   // fin du premier "si" : après le "sinon"
    }

    public function test_the_parser_reports_mistakes_with_their_line(): void
    {
        $result = (new ScriptParser)->parse("evenement a\n  donner truc\nfin\nevenement b\n  si question Oui ? et badge 1\n  fin\n  si pas question Non ?\n  fin\n  menu Choisis\n    dire oups\n  fin\nfin\nevenement c\n  dire Bonjour");

        $this->assertCount(5, $result['errors']);
        $this->assertStringStartsWith('ligne 2 :', $result['errors'][0]);     // donner quoi ?
        $this->assertStringStartsWith('ligne 7 :', $result['errors'][1]);     // "pas question" n'existe pas
        $this->assertStringStartsWith('ligne 10 :', $result['errors'][2]);    // menu : il faut "choix"
        $this->assertStringStartsWith('ligne 11 :', $result['errors'][3]);    // menu sans aucun choix
        $this->assertStringContainsString('"c" n\'est pas fermé', $result['errors'][4]);
        $this->assertArrayHasKey('b', $result['events']);
    }

    public function test_tiled_conditions(): void
    {
        $parser = new ScriptParser;
        $this->assertSame([[false, 'flag', ['garde_parti']]], $parser->parseCondition('garde_parti'));
        $this->assertSame([[true, 'flag', ['vu']], [false, 'badges', [2]]], $parser->parseCondition('pas drapeau vu et badges 2'));
        $this->expectException(ScriptError::class);
        $parser->parseCondition('question Tu viens ?');
    }

    // =====================================================================
    // Les événements dans le jeu
    // =====================================================================

    public function test_a_gift_is_given_only_once(): void
    {
        $this->world(<<<'TXT'
            evenement cadeau
                si drapeau recu
                    dire Bonne route, {joueur} !
                sinon
                    dire Tiens !
                    donner objet potion 3
                    drapeau recu
                fin
            fin
            TXT, ['npcs' => [[3, 3, ['name' => 'Prof', 'sprite' => 'npc_10', 'event' => 'cadeau']]]]);
        $sacha = GameWorld::character();

        $first = $this->start($sacha, 'cadeau')->assertOk()->assertJson(['done' => true, 'wait' => null]);
        $this->assertSame(['Tiens !', 'Sacha obtient 3 Potion !', 'Sacha range l\'objet dans la poche Soins.'], self::texts($first));
        $this->assertSame('Prof', $first->json('steps.0.name'));   // le nom du PNJ au-dessus de la boîte
        $this->assertSame(3, $sacha->fresh()->quantityOf(GameWorld::item('potion')));

        $second = $this->start($sacha, 'cadeau')->assertOk();
        $this->assertSame(['Bonne route, Sacha !'], self::texts($second));
        $this->assertSame(3, $sacha->fresh()->quantityOf(GameWorld::item('potion')));
    }

    public function test_a_question_waits_for_the_answer(): void
    {
        $this->world(<<<'TXT'
            evenement echange
                si question Un Super Bonbon contre une Potion ?
                    si objet potion
                        prendre objet potion
                        donner objet rare-candy
                    sinon
                        dire Tu n'as pas de Potion.
                    fin
                sinon
                    dire Dommage.
                fin
            fin
            TXT, ['npcs' => [[3, 3, ['sprite' => 'npc_10', 'event' => 'echange']]]]);
        $sacha = GameWorld::character();
        $sacha->addItem(GameWorld::item('potion'));

        $this->start($sacha, 'echange')->assertOk()
            ->assertJson(['done' => false, 'wait' => ['type' => 'question', 'text' => 'Un Super Bonbon contre une Potion ?']]);
        $this->resume($sacha, 5)->assertStatus(422);   // réponse impossible
        $this->resume($sacha, 0)->assertOk()->assertJson(['done' => true]);
        $this->assertSame(0, $sacha->fresh()->quantityOf(GameWorld::item('potion')));
        $this->assertSame(1, $sacha->fresh()->quantityOf(GameWorld::item('rare-candy')));
        $this->resume($sacha, 0)->assertStatus(422);   // plus rien en attente

        $this->start($sacha, 'echange');
        $this->assertSame(['Tu n\'as pas de Potion.'], self::texts($this->resume($sacha, 0)));
        $this->start($sacha, 'echange');
        $this->assertSame(['Dommage.'], self::texts($this->resume($sacha, 1)));
    }

    public function test_menus_and_variables(): void
    {
        $this->world(<<<'TXT'
            evenement quiz
                menu Ton type préféré ?
                    choix Feu
                        variable feu + 1
                    choix Eau
                        variable feu = 10
                    choix Rien
                fin
                si variable feu >= 2
                    dire Feu x{variable:feu} !
                fin
            fin
            TXT, ['signs' => [[3, 3, ['event' => 'quiz']]]]);
        $sacha = GameWorld::character();

        $this->start($sacha, 'quiz')->assertJson(['wait' => ['type' => 'menu', 'options' => ['Feu', 'Eau', 'Rien']]]);
        $this->assertSame([], self::texts($this->resume($sacha, 0)));
        $this->start($sacha, 'quiz');
        $this->assertSame(['Feu x2 !'], self::texts($this->resume($sacha, 0)));
        $this->start($sacha, 'quiz');
        $this->resume($sacha, 2);
        $this->assertSame(2, StoryState::var($sacha->fresh(), 'feu'));
    }

    public function test_show_if_and_hide_if_follow_the_flags(): void
    {
        $this->world(<<<'TXT'
            evenement ouvrir
                drapeau ouvert
            fin
            evenement garde
                dire Halte !
            fin
            TXT, ['npcs' => [
                [3, 3, ['sprite' => 'npc_10', 'event' => 'ouvrir']],
                [4, 3, ['sprite' => 'npc_10', 'event' => 'garde', 'hide_if' => 'ouvert']],
                [5, 3, ['sprite' => 'npc_10', 'show_if' => 'ouvert et pas badge 1']],
            ]]);
        $sacha = GameWorld::character();
        $guard = $this->objectId('garde');
        $other = collect(MapInfo::load('test_ville')->eventObjects())->firstWhere('event', null)['id'];

        $this->actingAs($sacha->user)->getJson("/jouer/{$sacha->id}/evenements?map=test_ville")->assertJson(['hidden' => [$other]]);
        $this->start($sacha, 'garde')->assertOk();
        $this->start($sacha, 'ouvrir')->assertJson(['hidden' => [$guard]]);
        $this->start($sacha, 'garde')->assertStatus(422);   // caché : son événement ne se joue plus
    }

    public function test_a_pokemon_gift_and_a_teleport(): void
    {
        GameWorld::map('test_route', []);
        $this->world(<<<'TXT'
            evenement eleveur
                donner pokemon pikachu 5 objet charcoal
                teleporter test_route 4 5 gauche
                dire Jamais lu
            fin
            TXT, ['npcs' => [[3, 3, ['sprite' => 'npc_10', 'event' => 'eleveur']]]]);
        $sacha = GameWorld::character();

        $response = $this->start($sacha, 'eleveur')->assertOk()->assertJson(['done' => true]);
        $types = collect($response->json('steps'))->pluck('type')->all();
        $this->assertSame(['say', 'nickname', 'party', 'teleport'], $types);
        $pikachu = $sacha->party()->whereHas('species', fn ($q) => $q->where('slug', 'pikachu'))->first();
        $this->assertSame('Charbon', $pikachu->heldItem->name);
        $this->assertSame(['test_route', 4, 5, 'left'], [$sacha->fresh()->current_map, $sacha->fresh()->position_x, $sacha->fresh()->position_y, $sacha->fresh()->facing]);
    }

    public function test_a_battle_that_can_be_lost_continues_the_event(): void
    {
        $trainer = Trainer::create(['slug' => 'rivale', 'name' => 'Lina', 'trainer_class' => 'Fillette', 'reward_money' => 100, 'sight_range' => 0, 'ai_level' => 1]);
        $trainer->pokemon()->create(['slot' => 1, 'pokemon_species_id' => PokemonSpecies::where('slug', 'mewtwo')->value('id'), 'level' => 100]);
        $this->world(<<<'TXT'
            evenement duel
                combat dresseur rivale perdable
                si resultat defaite
                    dire Perdu, mais ce n'est pas grave.
                sinon
                    dire Gagné !
                fin
            fin
            TXT, ['events' => [[3, 3, ['event' => 'duel', 'trigger' => 'step']]]]);
        $sacha = GameWorld::character([], 'bulbasaur', 3);
        $money = $sacha->money;

        $wait = $this->start($sacha, 'duel')->assertOk()->assertJson(['done' => false, 'wait' => ['type' => 'battle', 'trainer' => 'rivale']])->json('wait');
        $this->resume($sacha)->assertStatus(422);   // le combat n'est pas fini

        // Mewtwo N. 100 contre Bulbizarre N. 3 : on attaque jusqu'à la défaite
        $battles = app(BattleService::class);
        $battle = Battle::findOrFail($wait['battle']['id'] ?? Battle::latest('id')->value('id'));
        for ($i = 0; $i < 20 && $battle->fresh()->status === 'active'; $i++) {
            $result = $battles->act($battle->fresh(), ['type' => 'move', 'slot' => 1]);
        }
        $this->assertSame('loss', $battle->fresh()->result);
        $this->assertArrayNotHasKey('whiteout', $result['extra'] ?? $result);   // pas de "tout devient noir"
        $this->assertSame($money, $sacha->fresh()->money);                     // pas d'argent perdu
        $this->assertTrue($sacha->party()->first()->current_hp > 0);           // équipe soignée

        $this->assertSame(['Perdu, mais ce n\'est pas grave.'], self::texts($this->resume($sacha)->assertOk()));
    }

    public function test_the_checker_command(): void
    {
        $this->world("evenement ok\n  donner objet potion\n  deplacer prof gauche\nfin\n", ['npcs' => [[3, 3, ['sprite' => 'npc_10', 'event' => 'ok'], 'prof']]]);
        $this->artisan('game:scenario')->assertSuccessful();

        File::put("{$this->scenarioDir}/test_ville.txt", "evenement ok\n  donner objet inconnu\n  combat dresseur personne\nfin\n");
        GameWorld::map('test_ville', ['npcs' => [[3, 3, ['sprite' => 'npc_10', 'event' => 'manquant', 'hide_if' => 'question Hein ?']]]]);
        $this->artisan('game:scenario')
            ->expectsOutputToContain('l\'objet "inconnu" n\'existe pas')
            ->expectsOutputToContain('le dresseur "personne" n\'existe pas')
            ->expectsOutputToContain('"manquant" n\'existe ni dans test_ville.txt')
            ->expectsOutputToContain('hide_if')
            ->assertFailed();
    }

    public function test_errors_and_access(): void
    {
        $this->world("evenement casse\n  donner objet inconnu\nfin\n", ['npcs' => [
            [3, 3, ['sprite' => 'npc_10', 'event' => 'casse']],
            [4, 3, ['sprite' => 'npc_10', 'event' => 'absent']],
        ]]);
        $sacha = GameWorld::character();
        $ondine = GameWorld::character(['name' => 'Ondine']);

        $this->assertStringContainsString('objet inconnu : "inconnu"', self::texts($this->start($sacha, 'casse')->assertOk())[0]);
        $this->assertStringContainsString('"absent" n\'existe pas', self::texts($this->start($sacha, 'absent')->assertOk())[0]);
        $this->actingAs($ondine->user)->postJson("/jouer/{$sacha->id}/evenements", ['map' => 'test_ville', 'object' => $this->objectId('casse')])->assertNotFound();
        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/evenements", ['map' => 'test_ville', 'object' => 999])->assertStatus(422);
    }
}
