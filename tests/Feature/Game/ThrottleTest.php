<?php

namespace Tests\Feature\Game;

use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Les limites de requêtes (throttle) : chaque route a son propre compteur */
class ThrottleTest extends GameTestCase
{
    public function test_talking_and_picking_up_items_does_not_hit_the_limit(): void
    {
        GameWorld::map('test_ville', ['items' => [[2, 2, ['item' => 'potion']]]]);
        $sacha = GameWorld::character();

        // Avant : le Sac + les événements partageaient UN compteur avec toutes les autres routes ;
        // 30 requêtes en une minute suffisaient à bloquer le ramassage des objets (limite 30)
        for ($i = 0; $i < 40; $i++) {
            $this->actingAs($sacha->user)->getJson("/jouer/{$sacha->id}/equipe")->assertOk();
        }
        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/objets-carte", ['map' => 'test_ville', 'key' => '2_2'])->assertOk();

        // Une route reste limitée : au-delà, un message en français
        for ($i = 0; $i < 29; $i++) {
            $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/objets-carte", ['map' => 'test_ville', 'key' => '2_2']);
        }
        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/objets-carte", ['map' => 'test_ville', 'key' => '2_2'])
            ->assertStatus(429)->assertJson(['message' => 'Doucement ! Attends quelques secondes, puis réessaie.']);
    }
}
