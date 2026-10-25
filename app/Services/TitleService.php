<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Events\PlayerNotice;
use App\Models\Character;
use App\Models\Pokemon;
use App\Models\TitleHolder;
use App\Models\Trainer;
use Illuminate\Support\Facades\DB;

/**
 * Le "Tenant du titre" : un PNJ que tout le monde peut défier.
 * Le joueur qui le bat prend sa place : les autres joueurs affrontent alors une COPIE
 * de l'équipe qu'il avait au moment de sa victoire (une "photo", qui ne bouge plus ensuite).
 *
 * Règles :
 *   - le tenant actuel ne peut pas se défier lui-même ;
 *   - niveau maximum autorisé (trainers.level_cap), vérifié pour toute l'équipe du challenger ;
 *   - si deux joueurs battent le tenant en même temps, seul le PREMIER à finir prend le titre
 *     (la ligne du dresseur est verrouillée pendant le changement de tenant).
 */
class TitleService
{
    /** Le tenant actuel (null : personne ne l'a encore battu, c'est le dresseur d'origine) */
    public function holder(Trainer $trainer): ?TitleHolder
    {
        return TitleHolder::where('trainer_id', $trainer->id)->current()->latest('won_at')->latest('id')->first();
    }

    /** Ce que le jeu affiche : nom, apparence, depuis quand, combien de défenses, et l'historique */
    public function info(Trainer $trainer, ?Character $viewer = null): array
    {
        $holder = $this->holder($trainer);
        $history = TitleHolder::where('trainer_id', $trainer->id)->latest('won_at')->latest('id')->limit(5)->get();

        return [
            'slug' => $trainer->slug,
            'name' => $holder?->name ?? $trainer->name,
            'class' => $trainer->trainer_class,
            'gender' => $holder?->gender,
            // Sprite de carte : celui du joueur tenant (sa planche de marche), sinon celui du dresseur
            'overworld' => $holder?->avatarKey() ? null : $trainer->sprite_overworld,
            'playerSprite' => $holder?->avatarKey(),   // étape 17 : red, leaf, brendan, may
            'since' => $holder?->won_at?->format('d/m/Y'),
            'defenses' => $holder?->defenses ?? 0,
            'isMe' => $viewer && $holder && $holder->character_id === $viewer->id,
            'levelCap' => $trainer->level_cap,
            'team' => collect($holder?->team ?? $this->defaultTeam($trainer))->map(fn ($p) => [
                'speciesName' => $p['speciesName'] ?? '',
                'level' => $p['level'],
            ])->all(),
            'history' => $history->map(fn (TitleHolder $h) => [
                'name' => $h->name,
                'won' => $h->won_at?->format('d/m/Y'),
                'defenses' => $h->defenses,
                'current' => $h->lost_at === null,
            ])->all(),
        ];
    }

    /** Vérifie que ce personnage peut défier le tenant ; lance InvalidAction sinon */
    public function checkChallenger(Trainer $trainer, Character $character): void
    {
        $holder = $this->holder($trainer);
        if ($holder && $holder->character_id === $character->id) {
            throw new InvalidAction("C'est vous le {$trainer->trainer_class} ! Défendez votre titre contre les autres dresseurs.");
        }

        if ($trainer->level_cap) {
            $tooHigh = $character->battleParty()->where('level', '>', $trainer->level_cap)->with('species')->first();
            if ($tooHigh) {
                throw new InvalidAction("Seuls les Pokémon de niveau {$trainer->level_cap} ou moins sont autorisés. ({$tooHigh->display_name} est N. {$tooHigh->level}.)");
            }
        }
    }

    /** L'équipe à affronter : la photo du tenant, ou l'équipe d'origine du dresseur */
    public function team(Trainer $trainer): array
    {
        return $this->holder($trainer)?->team ?? $this->defaultTeam($trainer);
    }

    /**
     * Le challenger a gagné : il devient le tenant. Renvoie true s'il a bien pris le titre,
     * false si quelqu'un l'a pris entre-temps (le titre n'était plus celui affronté).
     */
    public function crown(Trainer $trainer, Character $winner, ?int $defeatedHolderId): bool
    {
        return DB::transaction(function () use ($trainer, $winner, $defeatedHolderId) {
            // Verrou : deux victoires simultanées passent l'une après l'autre
            Trainer::whereKey($trainer->id)->lockForUpdate()->first();

            $current = $this->holder($trainer);
            if (($current?->id) !== $defeatedHolderId) {
                return false;
            }

            $current?->forceFill(['lost_at' => now(), 'lost_to_character_id' => $winner->id])->save();

            TitleHolder::create([
                'trainer_id' => $trainer->id,
                'character_id' => $winner->id,
                'name' => $winner->name,
                'gender' => $winner->gender,
                'avatar' => $winner->avatarKey(),
                'team' => $this->snapshot($winner, $trainer->level_cap),
                'won_at' => now(),
            ]);

            // L'ancien tenant (un joueur) est prévenu en direct, s'il est connecté
            if ($current?->character_id && $current->character_id !== $winner->id) {
                $who = $winner->name;
                $title = $trainer->trainer_class;
                DB::afterCommit(fn () => PlayerNotice::send($current->character_id, 'title_lost', ['by' => $who, 'title' => $title]));
            }

            return true;
        });
    }

    /** Le tenant a repoussé un défi */
    public function defended(?int $holderId): void
    {
        if ($holderId) {
            TitleHolder::whereKey($holderId)->whereNull('lost_at')->increment('defenses');
        }
    }

    /**
     * La "photo" de l'équipe : tout ce qu'il faut pour recréer chaque Pokémon à l'identique.
     * Les niveaux sont ramenés à la limite (un Pokémon a pu monter d'un niveau pendant le combat).
     */
    public function snapshot(Character $character, ?int $levelCap = null): array
    {
        return $character->battleParty()->with('species', 'moves')->get()->map(fn (Pokemon $p) => [
            'speciesId' => $p->pokemon_species_id,
            'speciesName' => $p->species->name,
            'nickname' => $p->nickname,
            'level' => $levelCap ? min($p->level, $levelCap) : $p->level,
            'gender' => $p->gender,
            'shiny' => (bool) $p->is_shiny,
            'natureId' => $p->nature_id,
            'abilityId' => $p->ability_id,
            'heldItemId' => $p->held_item_id,
            'ivs' => collect(['hp', 'attack', 'defense', 'sp_attack', 'sp_defense', 'speed'])->mapWithKeys(fn ($s) => [$s => $p->{"iv_{$s}"}])->all(),
            'evs' => collect(['hp', 'attack', 'defense', 'sp_attack', 'sp_defense', 'speed'])->mapWithKeys(fn ($s) => [$s => $p->{"ev_{$s}"}])->all(),
            'moves' => $p->moves->sortBy('slot')->pluck('move_id')->values()->all(),
        ])->values()->all();
    }

    /** L'équipe d'origine du dresseur (trainer_pokemon), au même format que la photo */
    private function defaultTeam(Trainer $trainer): array
    {
        return $trainer->pokemon()->where('version', 0)->with('species')->get()->map(fn ($tp) => [
            'trainerPokemonId' => $tp->id,
            'speciesId' => $tp->pokemon_species_id,
            'speciesName' => $tp->species->name,
            'level' => $tp->level,
        ])->all();
    }
}
