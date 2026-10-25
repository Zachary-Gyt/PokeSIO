<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\GameWorld;

/**
 * Étape 21 : la base des tests du jeu.
 *
 * - Base de données vide en mémoire (SQLite), recréée pour chaque test (RefreshDatabase), puis le petit monde
 *   de GameWorld (quelques Pokémon, attaques, objets).
 * - Le dossier "public" est remplacé par un dossier temporaire : les cartes Tiled des tests y sont écrites
 *   (GameWorld::map) sans toucher à TES cartes.
 */
abstract class GameTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publicDir = sys_get_temp_dir() . '/pokesio-tests-' . uniqid();
        File::ensureDirectoryExists("{$this->publicDir}/game/maps");
        $this->app->usePublicPath($this->publicDir);
        GameWorld::seed();
    }

    /**
     * Sécurité : les tests vident la base avant chaque test. On refuse de tourner ailleurs que sur SQLite en mémoire
     * (par exemple si "php artisan config:cache" a figé la configuration MySQL : lancer d'abord "php artisan config:clear").
     */
    protected function beforeRefreshingDatabase()
    {
        $connection = config('database.default');
        if (config("database.connections.{$connection}.driver") !== 'sqlite' || config("database.connections.{$connection}.database") !== ':memory:') {
            throw new \RuntimeException('Tests arrêtés : ils doivent utiliser SQLite en mémoire, pas ta vraie base ! '
                . 'Lance "php artisan config:clear" puis relance les tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDir);
        parent::tearDown();
    }
}
