<?php

namespace Tests\Feature\Game;

use App\Models\Badge;
use App\Models\Character;
use App\Scenario\ScriptParser;
use App\Services\MapInfo;
use Illuminate\Support\Facades\File;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** L'histoire de la région : l'Œuf étrange du Professeur, le légendaire de Mme Goudet, les 16 badges */
class StoryTest extends GameTestCase
{
    private string $scenarioDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenarioDir = "{$this->publicDir}/scenario";
        File::ensureDirectoryExists($this->scenarioDir);
        config(['game.scenario_path' => $this->scenarioDir]);
    }

    private function world(string $script, string $event): int
    {
        GameWorld::map('test_ville', ['npcs' => [[3, 3, ['name' => 'Prof', 'sprite' => 'npc_10', 'event' => $event]]]]);
        File::put("{$this->scenarioDir}/test_ville.txt", $script);

        return collect(MapInfo::load('test_ville')->eventObjects())->firstWhere('event', $event)['id'];
    }

    private function talk(Character $character, int $object)
    {
        return $this->actingAs($character->user)->postJson("/jouer/{$character->id}/evenements", ['map' => 'test_ville', 'object' => $object]);
    }

    public function test_the_strange_egg_is_a_random_legendary(): void
    {
        $object = $this->world(<<<'TXT'
            evenement professeur
                si drapeau oeuf
                    dire Bon courage !
                sinon
                    donner oeuf legendaire 1000
                    drapeau oeuf
                fin
            fin
            TXT, 'professeur');
        $sacha = GameWorld::character();

        $response = $this->talk($sacha, $object)->assertOk();
        $egg = $sacha->party()->where('is_egg', true)->first();
        $this->assertNotNull($egg);
        $this->assertSame('mewtwo', $egg->species->slug);   // le seul légendaire des données de test
        $this->assertSame(1000, $egg->egg_steps);
        $this->assertSame(1, $egg->level);
        $this->assertContains('Sacha reçoit un Œuf !', collect($response->json('steps'))->pluck('text')->all());
        // Un Œuf ne compte pas encore dans le Pokédex
        $this->assertFalse($sacha->pokedexEntries()->where('pokemon_species_id', $egg->pokemon_species_id)->exists());

        $this->talk($sacha, $object)->assertOk();
        $this->assertSame(1, $sacha->party()->where('is_egg', true)->count());
    }

    public function test_mme_goudet_gives_a_legendary_with_eight_badges(): void
    {
        $object = $this->world(<<<'TXT'
            evenement goudet
                si drapeau donne
                    dire Bravo.
                sinon si badges 8
                    donner pokemon legendaire 50
                    drapeau donne
                sinon
                    dire Reviens avec {badges} badges de plus... non, avec 8 !
                fin
            fin
            TXT, 'goudet');
        $sacha = GameWorld::character();

        $this->talk($sacha, $object)->assertOk();
        $this->assertSame(1, $sacha->party()->count());

        foreach (range(1, 16) as $order) {
            Badge::forceCreate(['order' => $order, 'name' => "Badge {$order}", 'sprite' => (string) ($order - 1)]);
        }
        // 8 badges, peu importe la région (ici : 4 de Kanto et 4 de Johto)
        $sacha->badges()->attach(Badge::whereIn('order', [1, 2, 3, 4, 9, 10, 11, 12])->pluck('id'), ['obtained_at' => now()]);

        $this->talk($sacha, $object)->assertOk();
        $legend = $sacha->party()->where('is_egg', false)->whereHas('species', fn ($q) => $q->where('slug', 'mewtwo'))->first();
        $this->assertNotNull($legend);
        $this->assertSame(50, $legend->level);
    }

    /** Le vrai scénario de Mme Goudet : 8 badges = 1 légendaire, 16 badges = 2 légendaires et une Master Ball */
    public function test_mme_goudet_asks_for_sixteen_badges(): void
    {
        $script = File::get(resource_path('scenario/ort_office.txt'));
        $object = $this->world($script, 'goudet');
        GameWorld::item('master-ball');
        foreach (range(1, 16) as $order) {
            Badge::forceCreate(['order' => $order, 'name' => "Badge {$order}", 'sprite' => (string) ($order - 1)]);
        }
        $sacha = GameWorld::character();
        $sacha->badges()->attach(Badge::where('order', '<=', 8)->pluck('id'), ['obtained_at' => now()]);

        $this->talk($sacha, $object)->assertOk();
        $this->assertSame(1, $sacha->pokemon()->whereHas('species', fn ($q) => $q->where('slug', 'mewtwo'))->count());

        // Toujours 8 badges : elle demande les 16
        $texts = collect($this->talk($sacha, $object)->json('steps'))->pluck('text')->implode(' ');
        $this->assertStringContainsString('Obtiens-en seize', $texts);
        $this->assertSame(1, $sacha->pokemon()->whereHas('species', fn ($q) => $q->where('slug', 'mewtwo'))->count());

        // 16 badges : deux légendaires et une Master Ball, une seule fois
        $sacha->badges()->attach(Badge::where('order', '>', 8)->pluck('id'), ['obtained_at' => now()]);
        $this->talk($sacha, $object)->assertOk();
        $this->talk($sacha, $object)->assertOk();
        $this->assertSame(3, $sacha->pokemon()->whereHas('species', fn ($q) => $q->where('slug', 'mewtwo'))->count());
        $this->assertSame(1, $sacha->fresh()->quantityOf(GameWorld::item('master-ball')));
    }

    public function test_the_trainer_card_has_sixteen_badges(): void
    {
        foreach (range(1, 16) as $order) {
            Badge::forceCreate(['order' => $order, 'name' => "Badge {$order}", 'sprite' => (string) ($order - 1)]);
        }
        $sacha = GameWorld::character();
        $sacha->badges()->attach(Badge::where('order', 9)->value('id'), ['obtained_at' => now()]);

        $card = $this->actingAs($sacha->user)->getJson(route('trainer-card', $sacha))->assertOk()->json('badges');
        $this->assertCount(16, $card);
        $this->assertSame([9, 8, true], [$card[8]['order'], $card[8]['icon'], $card[8]['owned']]);
    }

    public function test_badge_numbers_up_to_sixteen_in_scripts(): void
    {
        $result = (new ScriptParser)->parse("evenement x\n si badge 16 et badges 12\n dire Oui\n fin\nfin");
        $this->assertSame([], $result['errors']);
    }

    /** Les vrais fichiers de scénario de la région s'écrivent sans erreur */
    public function test_the_region_scenarios_parse(): void
    {
        foreach (['cremieu', 'cremieu_player_house', 'cremieu_laboratory', 'road_1', 'bachut', 'ort_office', 'ort_canteen', 'ort_courtyard', 'ort_building_1_floor_0'] as $file) {
            $result = (new ScriptParser)->parse(File::get(resource_path("scenario/{$file}.txt")));
            $this->assertSame([], $result['errors'], $file);
        }
    }
}
