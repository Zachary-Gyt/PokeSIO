<?php

namespace Tests\Feature\Game;

use App\Battle\InvalidAction;
use App\Models\InventoryItem;
use App\Services\ItemUseService;
use App\Services\ShopService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Le Sac, les objets de soin, le Super Bonbon et la boutique */
class ItemsTest extends GameTestCase
{
    public function test_add_and_remove_items(): void
    {
        $sacha = GameWorld::character();
        $potion = GameWorld::item('potion');

        $sacha->addItem($potion, 3);
        $this->assertSame(3, $sacha->quantityOf($potion));
        $this->assertTrue($sacha->removeItem($potion, 2));
        $this->assertSame(1, $sacha->quantityOf($potion));
        $this->assertFalse($sacha->removeItem($potion, 5), 'on ne retire pas plus que ce qu\'on a');
        $this->assertSame(1, $sacha->quantityOf($potion));
    }

    public function test_a_potion_heals_20_hp_without_exceeding_the_max(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $bulbi->forceFill(['current_hp' => 5])->save();

        app(ItemUseService::class)->use($sacha, GameWorld::item('potion'), $bulbi);
        $this->assertSame(min($bulbi->max_hp, 25), $bulbi->fresh()->current_hp);
    }

    public function test_a_potion_has_no_effect_at_full_hp(): void
    {
        $sacha = GameWorld::character();
        $this->expectException(InvalidAction::class);

        app(ItemUseService::class)->use($sacha, GameWorld::item('potion'), $sacha->party()->first());
    }

    public function test_items_have_no_effect_on_eggs(): void
    {
        $sacha = GameWorld::character();
        $egg = GameWorld::give($sacha, 'rattata', 1);
        $egg->forceFill(['is_egg' => true, 'egg_steps' => 100])->save();

        $this->expectException(InvalidAction::class);
        app(ItemUseService::class)->use($sacha, GameWorld::item('rare-candy'), $egg);
    }

    public function test_a_rare_candy_gives_one_level(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();

        app(ItemUseService::class)->use($sacha, GameWorld::item('rare-candy'), $bulbi);
        $this->assertSame(11, $bulbi->fresh()->level);
    }

    public function test_buy_spends_money_and_sell_gives_half_back(): void
    {
        config(['shops.test' => [0 => ['potion', 'poke-ball']]]);
        $sacha = GameWorld::character(['money' => 1000]);
        $shop = app(ShopService::class);

        $shop->buy($sacha, 'test', GameWorld::item('potion'), 2);
        $this->assertSame(400, (int) $sacha->fresh()->money);
        $this->assertSame(2, $sacha->quantityOf(GameWorld::item('potion')));

        $total = $shop->sell($sacha->fresh(), GameWorld::item('potion'), 2);
        $this->assertSame(300, $total);
        $this->assertSame(700, (int) $sacha->fresh()->money);
    }

    public function test_cannot_buy_without_enough_money(): void
    {
        config(['shops.test' => [0 => ['potion']]]);
        $sacha = GameWorld::character(['money' => 100]);

        $this->expectException(InvalidAction::class);
        app(ShopService::class)->buy($sacha, 'test', GameWorld::item('potion'), 1);
    }

    public function test_cannot_carry_more_than_the_max_quantity(): void
    {
        config(['shops.test' => [0 => ['potion']]]);
        $sacha = GameWorld::character(['money' => 999999]);
        $sacha->addItem(GameWorld::item('potion'), InventoryItem::MAX_QUANTITY);

        $this->expectException(InvalidAction::class);
        app(ShopService::class)->buy($sacha, 'test', GameWorld::item('potion'), 1);
    }

    public function test_key_items_cannot_be_sold(): void
    {
        $sacha = GameWorld::character();
        $sacha->addItem(GameWorld::item('coin-case'));

        $this->expectException(InvalidAction::class);
        app(ShopService::class)->sell($sacha, GameWorld::item('coin-case'), 1);
    }
}
