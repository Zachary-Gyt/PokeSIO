<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use App\Services\TitleService;
use Illuminate\Support\Facades\Cache;

/**
 * L'ÉCRAN TITRE, avec à côté :
 *   - le(s) Tenant(s) du titre en jeu : nom, sprite, équipe, depuis quand, combien de défenses ;
 *   - le classement des joueurs (temps de jeu, Pokédex, badges).
 *
 * Le classement est mis en cache 60 secondes : la page d'accueil est la plus visitée,
 * inutile de recompter tous les personnages à chaque visite.
 */
class HomeController extends Controller
{
    /** Nombre de joueurs affichés par classement */
    private const TOP = 5;

    public function __invoke(TitleService $titles)
    {
        return view('home', [
            'starters' => PokemonSpecies::starters()->get(),
            'titles' => $this->titles($titles),
            'leaderboards' => Cache::remember('home:leaderboards', 60, fn () => $this->leaderboards()),
        ]);
    }

    /** Les Tenants du titre actuels (un par dresseur "is_title") */
    private function titles(TitleService $titles): array
    {
        return Trainer::where('is_title', true)->orderBy('id')->get()->map(function (Trainer $trainer) use ($titles) {
            $holder = $titles->holder($trainer);
            $team = $titles->team($trainer);
            $species = PokemonSpecies::whereIn('id', collect($team)->pluck('speciesId'))->get()->keyBy('id');

            return [
                'class' => $trainer->trainer_class,
                'name' => $holder?->name ?? $trainer->name,
                // Un joueur tenant : son sprite de joueur ; sinon le sprite du dresseur PNJ
                'sprite' => asset('game/sprites/trainers/battle/' . ($holder?->avatarKey() ? "player_{$holder->avatarKey()}" : $trainer->sprite_battle) . '.png'),
                'isPlayer' => (bool) $holder?->avatarKey(),
                'since' => $holder?->won_at?->format('d/m/Y'),
                'defenses' => $holder?->defenses ?? 0,
                'team' => collect($team)->map(fn ($p) => [
                    'icon' => $species[$p['speciesId']]?->sprite('icon'),
                    'label' => ($p['speciesName'] ?? '') . ' N.' . $p['level'],
                ])->filter(fn ($p) => $p['icon'])->values()->all(),
            ];
        })->all();
    }

    /** Les classements : [clé => ['label' => ..., 'rows' => [[name, value], ...]]] */
    private function leaderboards(): array
    {
        $format = fn (int $s) => intdiv($s, 3600) . ' h ' . str_pad(intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT);

        $dexSize = PokemonSpecies::where('is_obtainable', true)->count();

        $time = Character::where('play_time', '>', 0)
            ->orderByDesc('play_time')->orderBy('id')->limit(self::TOP)->get(['name', 'play_time'])
            ->map(fn ($c) => ['name' => $c->name, 'value' => $format($c->play_time)]);

        $dex = Character::withCount(['pokedexEntries as caught' => fn ($q) => $q->where('caught', true)])
            ->orderByDesc('caught')->orderBy('id')->limit(self::TOP)->get(['id', 'name'])
            ->filter(fn ($c) => $c->caught > 0)
            ->map(fn ($c) => ['name' => $c->name, 'value' => "{$c->caught} / {$dexSize}"]);

        $badges = Character::withCount('badges')
            ->orderByDesc('badges_count')->orderBy('id')->limit(self::TOP)->get(['id', 'name'])
            ->filter(fn ($c) => $c->badges_count > 0)
            ->map(fn ($c) => ['name' => $c->name, 'value' => $c->badges_count . ' badge' . ($c->badges_count > 1 ? 's' : '')]);

        return [
            'temps' => ['label' => 'Temps de jeu', 'rows' => $time->all()],
            'pokedex' => ['label' => 'Pokédex', 'rows' => $dex->values()->all()],
            'badges' => ['label' => 'Badges', 'rows' => $badges->values()->all()],
        ];
    }
}
