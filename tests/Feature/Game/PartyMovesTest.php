<?php

namespace Tests\Feature\Game;

use App\Models\Move;
use App\Models\PokemonSpecies;
use App\Services\BattleService;
use App\Services\MoveTutorService;
use Illuminate\Support\Facades\DB;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Changer les capacités depuis le Résumé (remplace le Maître des capacités) */
class PartyMovesTest extends GameTestCase
{
    private function id(string $slug): int
    {
        return (int) Move::where('slug', $slug)->value('id');
    }

    private function choices($pokemon): array
    {
        return collect(app(MoveTutorService::class)->options($pokemon->fresh()))->pluck('id')->all();
    }

    public function test_every_move_that_enters_the_moveset_is_remembered(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $known = $bulbi->moves()->pluck('move_id')->sort()->values()->all();

        $remembered = DB::table('pokemon_learned_moves')->where('pokemon_id', $bulbi->id)->pluck('move_id')->sort()->values()->all();
        $this->assertSame($known, $remembered);

        // Une CT (ici CS01 Coupe) apprise par-dessus une capacité : Coupe est retenue
        $bulbi->moves()->where('slot', 1)->first()->forceFill(['move_id' => $this->id('cut')])->save();
        $this->assertTrue(DB::table('pokemon_learned_moves')->where('pokemon_id', $bulbi->id)->where('move_id', $this->id('cut'))->exists());
    }

    public function test_options_include_level_up_moves_and_every_move_learned_before(): void
    {
        $sacha = GameWorld::character();   // Bulbizarre N. 10
        $bulbi = $sacha->party()->first();

        // Il a connu Flammèche autrefois (une CT, par exemple), puis l'a oubliée
        $slot = $bulbi->moves()->where('slot', 1)->first();
        $first = (int) $slot->move_id;
        $slot->forceFill(['move_id' => $this->id('ember')])->save();
        $slot->forceFill(['move_id' => $first])->save();

        $ids = $this->choices($bulbi);
        $this->assertContains($this->id('ember'), $ids);
        $this->assertContains($this->id('vine-whip'), $ids);
        $this->assertNotContains($this->id('razor-leaf'), $ids, 'appris au N. 19 : pas encore');
        $this->assertNotContains($this->id('water-gun'), $ids, 'jamais appris');
    }

    public function test_replace_a_move_from_the_summary(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $old = $bulbi->moves()->with('move')->where('slot', 1)->first();
        DB::table('pokemon_learned_moves')->insert(['pokemon_id' => $bulbi->id, 'move_id' => $this->id('cut')]);   // une CS déjà apprise

        $response = $this->actingAs($sacha->user)->postJson(route('party.moves.replace', $sacha), [
            'pokemon_id' => $bulbi->id, 'slot' => 1, 'move_id' => $this->id('cut'),
        ])->assertOk();

        $new = $bulbi->moves()->with('move')->where('slot', 1)->first();
        $this->assertSame('cut', $new->move->slug);
        $this->assertSame(30, (int) $new->current_pp);   // PP au maximum
        $this->assertSame('cut', $response->json('pokemon.moves.0.slug'));
        // L'ancienne capacité reste disponible pour plus tard
        $this->assertContains((int) $old->move_id, $this->choices($bulbi));

        // Et la CS se remplace aussi librement
        $this->actingAs($sacha->user)->postJson(route('party.moves.replace', $sacha), [
            'pokemon_id' => $bulbi->id, 'slot' => 1, 'move_id' => (int) $old->move_id,
        ])->assertOk();
        $this->assertSame((int) $old->move_id, (int) $bulbi->moves()->where('slot', 1)->value('move_id'));
    }

    public function test_the_list_of_options_over_http(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();

        $moves = $this->actingAs($sacha->user)->getJson(route('party.moves', $sacha) . "?pokemon_id={$bulbi->id}")->assertOk()->json('moves');

        $this->assertSame($bulbi->moves()->count(), collect($moves)->whereNotNull('slot')->count());
    }

    public function test_a_move_can_fill_an_empty_slot(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $bulbi->moves()->where('slot', '>', 1)->delete();
        $remaining = (int) $bulbi->moves()->value('move_id');
        $other = collect($this->choices($bulbi))->first(fn ($id) => $id !== $remaining);

        $this->actingAs($sacha->user)->postJson(route('party.moves.replace', $sacha), [
            'pokemon_id' => $bulbi->id, 'slot' => 2, 'move_id' => $other,
        ])->assertOk();

        $this->assertSame([$remaining, $other], $bulbi->moves()->orderBy('slot')->pluck('move_id')->map(fn ($id) => (int) $id)->all());
        // Pas de trou : la 4e place n'est pas encore accessible
        $this->actingAs($sacha->user)->postJson(route('party.moves.replace', $sacha), [
            'pokemon_id' => $bulbi->id, 'slot' => 4, 'move_id' => $this->id('growl'),
        ])->assertStatus(422);
    }

    public function test_refused_cases(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $known = (int) $bulbi->moves()->where('slot', 2)->value('move_id');
        $post = fn (array $data) => $this->actingAs($sacha->user)->postJson(route('party.moves.replace', $sacha), ['pokemon_id' => $bulbi->id, 'slot' => 1] + $data);

        $post(['move_id' => $this->id('water-gun')])->assertStatus(422);   // jamais apprise
        $post(['move_id' => $known])->assertStatus(422);                   // déjà connue

        // Le Pokémon d'un autre joueur
        $other = GameWorld::character();
        $this->actingAs($other->user)->postJson(route('party.moves.replace', $sacha), [
            'pokemon_id' => $bulbi->id, 'slot' => 1, 'move_id' => $this->id('tackle'),
        ])->assertNotFound();
    }

    public function test_not_during_a_battle(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        DB::table('pokemon_learned_moves')->insert(['pokemon_id' => $bulbi->id, 'move_id' => $this->id('ember')]);
        app(BattleService::class)->startSpawn($sacha, PokemonSpecies::where('slug', 'rattata')->first(), 3);
        $free = $this->id('ember');

        $this->actingAs($sacha->user)->postJson(route('party.moves.replace', $sacha), [
            'pokemon_id' => $bulbi->id, 'slot' => 1, 'move_id' => $free,
        ])->assertStatus(422)->assertJson(['message' => 'Impossible pendant un combat !']);
    }
}
