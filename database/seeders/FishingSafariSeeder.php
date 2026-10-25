<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

/**
 * Étape 15 : les trois cannes à pêche et la Safari Ball.
 *
 *   php artisan db:seed --class=FishingSafariSeeder
 *
 * Un objet déjà importé de PokéAPI garde son nom et sa description : on vérifie seulement sa poche
 * et son effet. Le seeder peut être relancé sans risque.
 */
class FishingSafariSeeder extends Seeder
{
    public function run(): void
    {
        $rods = [
            'old-rod' => ['Canne', 1, 'Une vieille canne à pêche toute simple. Utilisez-la face à l\'eau pour pêcher des Pokémon.'],
            'good-rod' => ['Super Canne', 2, 'Une bonne canne à pêche. Elle permet d\'attraper de meilleurs Pokémon.'],
            'super-rod' => ['Méga Canne', 3, 'La meilleure canne à pêche. Les Pokémon les plus rares mordent à l\'hameçon.'],
        ];
        foreach ($rods as $slug => [$name, $power, $description]) {
            $this->upsert($slug, [
                'pocket' => 'key_items', 'consumable' => false, 'tradable' => false, 'holdable' => false,
                'usable_overworld' => true, 'effect_code' => 'fish', 'effect_value' => $power,
            ], ['name' => $name, 'category' => 'gameplay', 'description' => $description, 'price' => 0, 'sell_price' => 0]);
        }

        // La Safari Ball : elle ne va jamais dans le Sac (le Parc en prête 30), mais un Pokémon capturé
        // au Parc garde le souvenir de sa Ball (pokemon.caught_ball_id)
        $this->upsert('safari-ball', ['pocket' => 'balls', 'effect_code' => 'catch', 'effect_value' => 15], [
            'name' => 'Safari Ball', 'category' => 'special-balls', 'price' => 0, 'sell_price' => 0,
            'description' => 'Une Poké Ball spéciale, utilisable seulement au Parc Safari.',
            'consumable' => true, 'tradable' => true, 'usable_in_battle' => false, 'usable_overworld' => false, 'holdable' => true,
        ]);
    }

    /** $always : écrit à chaque fois ; $create : seulement si l'objet n'existe pas encore */
    private function upsert(string $slug, array $always, array $create): void
    {
        $item = Item::where('slug', $slug)->first();
        if ($item) {
            $item->forceFill($always)->save();

            return;
        }
        Item::forceCreate(['slug' => $slug] + $always + $create);
    }
}
