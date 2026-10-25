<?php

namespace Tests\Feature;

use Tests\GameTestCase;

/** La page d'accueil s'affiche (avec le petit monde des tests) */
class ExampleTest extends GameTestCase
{
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertOk();
    }
}
