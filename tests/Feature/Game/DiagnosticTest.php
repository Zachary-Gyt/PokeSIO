<?php

namespace Tests\Feature\Game;

use Illuminate\Support\Facades\Log;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Les images que le jeu n'a pas pu charger sont notées dans le journal du serveur */
class DiagnosticTest extends GameTestCase
{
    public function test_a_failed_image_is_logged(): void
    {
        Log::spy();
        $sacha = GameWorld::character();
        $ondine = GameWorld::character(['name' => 'Ondine']);

        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/diagnostic/image", ['key' => 'pkmn_x', 'url' => '/game/x.png', 'scene' => 'Battle'])
            ->assertNoContent();
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Image non chargée' && $context['url'] === '/game/x.png');

        // Pas pour le personnage d'un autre compte
        $this->actingAs($ondine->user)->postJson("/jouer/{$sacha->id}/diagnostic/image", ['key' => 'x'])->assertNotFound();
    }
}
