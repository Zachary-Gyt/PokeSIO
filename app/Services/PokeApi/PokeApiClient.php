<?php

namespace App\Services\PokeApi;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Petit client PokéAPI avec cache disque.
 * Chaque réponse est stockée en JSON : un deuxième import ne fait plus aucun appel réseau
 * (et on respecte la politique d'usage équitable de PokéAPI).
 */
class PokeApiClient
{
    private string $baseUrl;
    private string $cachePath;

    public function __construct(?string $baseUrl = null, ?string $cachePath = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('pokeapi.base_url'), '/');
        $this->cachePath = $cachePath ?? config('pokeapi.cache_path');
    }

    /**
     * @param string $endpoint "pokemon-species/1" ou une URL complète renvoyée par l'API
     */
    public function get(string $endpoint): array
    {
        $url = str_starts_with($endpoint, 'http')
            ? $endpoint
            : $this->baseUrl . '/' . ltrim($endpoint, '/');

        $file = $this->cachePath . '/' . md5(rtrim($url, '/')) . '.json';

        if (File::exists($file)) {
            return json_decode(File::get($file), true);
        }

        // throw: false -> on gère l'échec nous-mêmes, avec l'URL dans le message.
        // On ne réessaie que les erreurs réseau / serveur, pas les 404.
        $response = Http::acceptJson()
            ->timeout(30)
            ->retry(3, 1000, fn ($e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false)
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException("PokéAPI : erreur {$response->status()} sur {$url}");
        }

        File::ensureDirectoryExists($this->cachePath);
        File::put($file, $response->body());

        usleep(50_000); // 50 ms entre deux appels réseau, par politesse

        return $response->json();
    }

    /** Liste complète d'une ressource : "type", "nature"... */
    public function list(string $resource): array
    {
        return $this->get("{$resource}?limit=2000")['results'] ?? [];
    }
}
