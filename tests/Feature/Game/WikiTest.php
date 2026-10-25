<?php

namespace Tests\Feature\Game;

use App\Models\PokemonSpecies;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Le Wiki : chaque page s'affiche (public, sans connexion) */
class WikiTest extends GameTestCase
{
    public function test_every_wiki_page_renders(): void
    {
        GameWorld::character([], 'bulbasaur', 5);   // crée les données de base (espèces, capacités...)
        $species = PokemonSpecies::first();

        $urls = ['/wiki', '/wiki/pokedex', "/wiki/pokedex/{$species->slug}", '/wiki/evolutions', '/wiki/talents',
            '/wiki/capacites', '/wiki/types', '/wiki/objets', '/wiki/pension', '/wiki/guide', '/wiki/combat'];
        foreach (['baies', 'peche', 'safari', 'casino', 'jour-nuit'] as $slug) {
            $urls[] = "/wiki/activites/{$slug}";
        }

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/wiki/activites/inconnue')->assertNotFound();
    }

    public function test_the_battle_page_shows_the_obedience_levels(): void
    {
        $this->get('/wiki/combat')->assertOk()->assertSee('Obéissance')->assertSee('Toujours');
    }
}
