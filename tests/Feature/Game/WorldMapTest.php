<?php

namespace Tests\Feature\Game;

use App\Services\WorldMap;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Étape 25 : les cartes collées (mondes Tiled *.world) */
class WorldMapTest extends GameTestCase
{
    /** Trois cartes : ville (12 x 10) | route (12 x 10) à l'ouest, et une grotte loin au nord-est */
    private function world(array $extra = []): void
    {
        GameWorld::map('test_ville');
        GameWorld::map('test_route');
        GameWorld::map('test_loin');
        file_put_contents(public_path('game/maps/region.world'), json_encode(['type' => 'world', 'maps' => array_merge([
            ['fileName' => 'test_ville.tmj', 'x' => 0, 'y' => 0, 'width' => 384, 'height' => 320],
            ['fileName' => 'test_route.tmj', 'x' => -384, 'y' => 0, 'width' => 384, 'height' => 320],
            ['fileName' => 'test_loin.tmj', 'x' => 3200, 'y' => -3200, 'width' => 384, 'height' => 320],
        ], $extra)]));
    }

    public function test_maps_and_neighbors(): void
    {
        $this->world();
        $world = app(WorldMap::class);

        $this->assertSame(['world' => 'region', 'x' => -12, 'y' => 0, 'w' => 12, 'h' => 10], $world->maps()['test_route']);
        $this->assertSame(['test_route'], array_keys($world->neighbors('test_ville')));   // la carte lointaine n'est pas dessinée
        $this->assertSame(-12, $world->neighbors('test_ville')['test_route']['dx']);
        $this->assertSame(['map' => 'test_route', 'x' => 11, 'y' => 4], $world->mapAt('test_ville', -1, 4));
        $this->assertNull($world->mapAt('test_ville', 3, -1));
        $this->assertSame([], $world->neighbors('dev_inconnue'));
    }

    public function test_the_game_receives_the_world(): void
    {
        $this->world();
        $sacha = GameWorld::character();
        @mkdir(public_path('js/game'), 0777, true);   // la page du jeu lit la date de ses scripts

        $config = $this->actingAs($sacha->user)->get("/jouer/{$sacha->id}")->assertOk()->viewData('gameConfig');
        $this->assertSame(['world' => 'region', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 10], (array) $config['world']['maps']->test_ville);
    }

    public function test_the_checker_finds_problems(): void
    {
        $this->world([
            ['fileName' => 'introuvable.tmj', 'x' => 0, 'y' => 640, 'width' => 32, 'height' => 32],
            ['fileName' => 'test_ville.tmj', 'x' => 10, 'y' => 0, 'width' => 384, 'height' => 320],   // en double, et pas sur la grille
        ]);
        GameWorld::map('test_dessus');
        file_put_contents(public_path('game/maps/zz.world'), json_encode(['maps' => [
            ['fileName' => 'test_dessus.tmj', 'x' => 0, 'y' => 0], ['fileName' => 'test_loin.tmj', 'x' => 64, 'y' => 64],
        ]]));

        $messages = collect(app(WorldMap::class)->problems())->pluck(1)->join("\n");
        $this->assertStringContainsString('"introuvable.tmj" est introuvable', $messages);
        $this->assertStringContainsString('test_ville n\'est pas alignée', $messages);
        $this->artisan('game:monde')->expectsOutputToContain('collée à : test_route (à l\'ouest)')->assertFailed();
    }
}
