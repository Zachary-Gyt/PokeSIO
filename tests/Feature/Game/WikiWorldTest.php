<?php

namespace Tests\Feature\Game;

use App\Models\Item;
use Illuminate\Support\Facades\File;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Le Wiki des objets : seulement les lieux du monde, et pas les objets d'après la 4e génération introuvables */
class WikiWorldTest extends GameTestCase
{
    public function test_item_sources_come_only_from_the_world(): void
    {
        GameWorld::map('bachut_test', ['items' => [[2, 2, ['item' => 'potion']]]], ['displayName' => 'Bachut Test']);
        GameWorld::map('bourg_demo_test', ['items' => [[2, 2, ['item' => 'charcoal']]]], ['displayName' => 'Bourg Démo']);
        GameWorld::map('cremieu_test', ['npcs' => [[3, 3, ['name' => 'Cuisinier', 'sprite' => 'npc_10', 'event' => 'cuisinier']]]], ['displayName' => 'Crémieu Test']);
        $dir = "{$this->publicDir}/scenario";
        File::ensureDirectoryExists($dir);
        config(['game.scenario_path' => $dir]);
        File::put("{$dir}/cremieu_test.txt", "evenement cuisinier\n nom Cuisinier\n si badge 1\n  donner objet charcoal 1\n fin\nfin");
        Item::where('slug', 'oval-charm')->update(['name' => 'Charme Ovale']);   // (déjà dans les données de test)

        $html = $this->get('/wiki/objets')->assertOk()->getContent();
        $this->assertStringContainsString('Poké Ball : Bachut Test', $html);
        $this->assertStringNotContainsString('Bourg Démo', $html);
        $this->assertStringContainsString('Cadeau : Cuisinier (Crémieu Test) — Badge n° 1', $html);
        $this->assertStringNotContainsString('Charme Ovale', $html);   // 5e génération, introuvable en jeu
        $this->assertStringContainsString('Potion', $html);
    }
}
