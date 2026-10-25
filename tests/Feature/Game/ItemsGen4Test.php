<?php

namespace Tests\Feature\Game;

use App\Battle\InvalidAction;
use App\Models\Item;
use App\Services\BagService;
use App\Services\FieldItemService;
use App\Services\FriendshipService;
use App\Services\ItemUseService;
use Database\Seeders\ItemsGen4Seeder;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Étape 22 : les objets des générations 2 à 4, hors combat, et le Bonheur */
class ItemsGen4Test extends GameTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ItemsGen4Seeder::class);
    }

    public function test_the_seeder_adds_items_and_keeps_existing_ones(): void
    {
        $this->assertGreaterThan(250, Item::count());
        $this->assertSame('battle', GameWorld::item('x-attack')->pocket);
        $this->assertSame('battle_stat', GameWorld::item('x-attack')->effect_code);
        $this->assertSame('mail', GameWorld::item('grass-mail')->pocket);
        $this->assertSame('held_items', GameWorld::item('choice-band')->pocket);
        $this->assertSame('Potion', GameWorld::item('potion')->name, 'un objet existant garde son nom');
        $this->assertSame(300, GameWorld::item('potion')->price, 'et son prix');

        $count = Item::count();
        $this->seed(ItemsGen4Seeder::class);
        $this->assertSame($count, Item::count(), 'relancer le seeder ne crée pas de doublons');
    }

    public function test_bag_pockets(): void
    {
        $sacha = GameWorld::character();
        $sacha->addItem(GameWorld::item('x-attack'));
        $sacha->addItem(GameWorld::item('grass-mail'));
        $bag = app(BagService::class)->contents($sacha);

        $this->assertSame('x-attack', $bag['pockets'][7][0]['slug']);
        $this->assertSame('grass-mail', $bag['pockets'][6][0]['slug']);
        $this->assertFalse($bag['pockets'][7][0]['needsPokemon'], 'un objet X agit sur le Pokémon au combat');
    }

    public function test_pp_up_and_pp_max(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $tackle = $bulbi->moves()->orderBy('slot')->first();   // Charge : 35 PP
        $sacha->addItem(GameWorld::item('pp-up'));
        $sacha->addItem(GameWorld::item('pp-max'));
        $use = app(ItemUseService::class);

        $use->use($sacha, GameWorld::item('pp-up'), $bulbi->fresh(), $tackle->slot);
        $this->assertSame(42, $tackle->fresh()->max_pp);
        $use->use($sacha, GameWorld::item('pp-max'), $bulbi->fresh(), $tackle->slot);
        $this->assertSame(56, $tackle->fresh()->max_pp);
        $this->assertSame(56, $tackle->fresh()->current_pp);

        $sacha->addItem(GameWorld::item('pp-up'));
        $this->expectException(InvalidAction::class);
        $use->use($sacha, GameWorld::item('pp-up'), $bulbi->fresh(), $tackle->slot);
    }

    public function test_ev_berries_lower_evs_and_raise_friendship(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $bulbi->forceFill(['ev_attack' => 25, 'friendship' => 70])->save();
        $sacha->addItem(GameWorld::item('kelpsy-berry'));

        app(ItemUseService::class)->use($sacha, GameWorld::item('kelpsy-berry'), $bulbi->fresh());

        $this->assertSame(15, (int) $bulbi->fresh()->ev_attack);
        $this->assertSame(80, (int) $bulbi->fresh()->friendship);
    }

    public function test_bitter_medicine_lowers_friendship(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $bulbi->forceFill(['current_hp' => 1, 'friendship' => 70])->save();
        $sacha->addItem(GameWorld::item('energy-root'));

        app(ItemUseService::class)->use($sacha, GameWorld::item('energy-root'), $bulbi->fresh());

        $this->assertSame($bulbi->max_hp, $bulbi->fresh()->current_hp);
        $this->assertSame(60, (int) $bulbi->fresh()->friendship);
    }

    public function test_the_blue_flute_wakes_up_and_is_kept(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $bulbi->forceFill(['status' => 'sleep', 'sleep_turns' => 3])->save();
        $sacha->addItem(GameWorld::item('blue-flute'));

        $r = app(ItemUseService::class)->use($sacha, GameWorld::item('blue-flute'), $bulbi->fresh());

        $this->assertSame('none', $bulbi->fresh()->status);
        $this->assertSame(1, $sacha->quantityOf(GameWorld::item('blue-flute')));
        $this->assertStringContainsString('jouez', $r['messages'][0]);
    }

    public function test_sacred_ash_revives_the_whole_party(): void
    {
        $sacha = GameWorld::character();
        $pika = GameWorld::give($sacha, 'pikachu', 10);
        $rattata = GameWorld::give($sacha, 'rattata', 10);
        $pika->forceFill(['current_hp' => 0])->save();
        $rattata->forceFill(['current_hp' => 0])->save();
        $sacha->addItem(GameWorld::item('sacred-ash'));

        app(FieldItemService::class)->useSacredAsh($sacha, GameWorld::item('sacred-ash'));

        $this->assertSame($pika->max_hp, $pika->fresh()->current_hp);
        $this->assertSame($rattata->max_hp, $rattata->fresh()->current_hp);
        $this->assertSame(0, $sacha->quantityOf(GameWorld::item('sacred-ash')));
    }

    public function test_black_flute_lowers_encounters_on_this_map(): void
    {
        $sacha = GameWorld::character();
        $r = app(FieldItemService::class)->useFlute($sacha, GameWorld::item('black-flute'), 'test_route');

        $this->assertSame(['map' => 'test_route', 'rate' => 50], $r['encounterRate']);
    }

    public function test_friendship_rules(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $friendship = app(FriendshipService::class);

        $bulbi->friendship = 70;
        $this->assertSame(75, $friendship->change($bulbi, 'levelup'));
        $bulbi->friendship = 150;
        $this->assertSame(153, $friendship->change($bulbi, 'levelup'));
        $bulbi->friendship = 3;
        $this->assertSame(0, $friendship->change($bulbi, 'powder'));

        // Grelot Zen : x1,5 ; Luxe Ball : +1
        $bulbi->forceFill(['friendship' => 70, 'held_item_id' => GameWorld::item('soothe-bell')->id,
            'caught_ball_id' => GameWorld::item('luxury-ball')->id])->save();
        $this->assertSame(79, $friendship->change($bulbi->fresh(), 'levelup'));   // (5 + 1) x 1,5 = 9
    }

    public function test_walking_raises_friendship_every_128_steps(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $bulbi->forceFill(['friendship' => 70])->save();

        app(FriendshipService::class)->steps($sacha, 100);
        $this->assertSame(70, (int) $bulbi->fresh()->friendship);
        app(FriendshipService::class)->steps($sacha->fresh(), 60);
        $this->assertSame(71, (int) $bulbi->fresh()->friendship);
    }
}
