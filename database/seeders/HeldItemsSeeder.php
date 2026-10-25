<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

/**
 * Étape 14 : les objets à tenir et la Bicyclette.
 *
 *   php artisan db:seed --class=HeldItemsSeeder
 *
 * - Un objet DÉJÀ dans la table items (importé de PokéAPI) garde son nom, son prix et sa description :
 *   on corrige seulement sa poche et on le rend "tenable".
 * - Un objet absent est créé avec les valeurs ci-dessous.
 * Le seeder peut être relancé sans risque.
 */
class HeldItemsSeeder extends Seeder
{
    /** slug => [nom, poche, prix, description] */
    private const ITEMS = [
        'exp-share' => ['Multi Exp', 'held_items', 0, 'Le Pokémon qui le tient reçoit une partie de l\'Exp. d\'un combat, même s\'il n\'y a pas participé.'],
        'lucky-egg' => ['Œuf Chance', 'held_items', 0, 'Un œuf plein de bonheur. Le Pokémon qui le tient gagne plus de points d\'Exp.'],
        'amulet-coin' => ['Pièce Rune', 'held_items', 0, 'Double l\'argent gagné contre un Dresseur si le Pokémon qui la tient a participé au combat.'],
        'leftovers' => ['Restes', 'held_items', 0, 'Le Pokémon qui les tient récupère un peu de PV à chaque tour.'],
        'everstone' => ['Pierre Stase', 'held_items', 0, 'Le Pokémon qui la tient ne peut pas évoluer.'],
        'quick-claw' => ['Vive Griffe', 'held_items', 0, 'Permet parfois au Pokémon qui la tient d\'agir en premier.'],
        'kings-rock' => ['Roche Royale', 'held_items', 0, 'Peut apeurer la cible quand le Pokémon qui la tient l\'attaque.'],
        'focus-band' => ['Bandeau', 'held_items', 0, 'Permet parfois au Pokémon qui le tient de tenir le coup avec 1 PV.'],
        'scope-lens' => ['Lentilscope', 'held_items', 0, 'Augmente le taux de coups critiques du Pokémon qui la tient.'],
        'smoke-ball' => ['Boule Fumée', 'held_items', 0, 'Permet de toujours fuir un Pokémon sauvage.'],
        'charcoal' => ['Charbon', 'held_items', 1000, 'Augmente la puissance des capacités de type Feu.'],
        'mystic-water' => ['Eau Mystique', 'held_items', 1000, 'Augmente la puissance des capacités de type Eau.'],
        'miracle-seed' => ['Grain Miracle', 'held_items', 1000, 'Augmente la puissance des capacités de type Plante.'],
        'magnet' => ['Aimant', 'held_items', 1000, 'Augmente la puissance des capacités de type Électrik.'],
        'never-melt-ice' => ['Glace Éternelle', 'held_items', 1000, 'Augmente la puissance des capacités de type Glace.'],
        'black-belt' => ['Ceinture Noire', 'held_items', 1000, 'Augmente la puissance des capacités de type Combat.'],
        'poison-barb' => ['Pic Venin', 'held_items', 1000, 'Augmente la puissance des capacités de type Poison.'],
        'soft-sand' => ['Sable Doux', 'held_items', 1000, 'Augmente la puissance des capacités de type Sol.'],
        'sharp-beak' => ['Bec Pointu', 'held_items', 1000, 'Augmente la puissance des capacités de type Vol.'],
        'twisted-spoon' => ['Cuillère Tordue', 'held_items', 1000, 'Augmente la puissance des capacités de type Psy.'],
        'silver-powder' => ['Poudre Argentée', 'held_items', 1000, 'Augmente la puissance des capacités de type Insecte.'],
        'hard-stone' => ['Pierre Dure', 'held_items', 1000, 'Augmente la puissance des capacités de type Roche.'],
        'spell-tag' => ['Rune Sort', 'held_items', 1000, 'Augmente la puissance des capacités de type Spectre.'],
        'dragon-fang' => ['Croc Dragon', 'held_items', 1000, 'Augmente la puissance des capacités de type Dragon.'],
        'silk-scarf' => ['Mouchoir Soie', 'held_items', 1000, 'Augmente la puissance des capacités de type Normal.'],
        'black-glasses' => ['Lunettes Noires', 'held_items', 1000, 'Augmente la puissance des capacités de type Ténèbres.'],
        'metal-coat' => ['Peau Métal', 'held_items', 1000, 'Augmente la puissance des capacités de type Acier.'],
        // Les Baies restent dans la poche Baies
        'oran-berry' => ['Baie Oran', 'berries', 20, 'Tenue par un Pokémon, elle lui rend 10 PV quand ses PV tombent à la moitié.'],
        'sitrus-berry' => ['Baie Sitrus', 'berries', 20, 'Tenue par un Pokémon, elle lui rend un quart de ses PV quand ils tombent à la moitié.'],
        'cheri-berry' => ['Baie Ceriz', 'berries', 20, 'Tenue par un Pokémon, elle soigne la paralysie.'],
        'chesto-berry' => ['Baie Maron', 'berries', 20, 'Tenue par un Pokémon, elle le réveille.'],
        'pecha-berry' => ['Baie Pêcha', 'berries', 20, 'Tenue par un Pokémon, elle soigne l\'empoisonnement.'],
        'rawst-berry' => ['Baie Fraive', 'berries', 20, 'Tenue par un Pokémon, elle soigne une brûlure.'],
        'aspear-berry' => ['Baie Willia', 'berries', 20, 'Tenue par un Pokémon, elle le dégèle.'],
        'lum-berry' => ['Baie Prine', 'berries', 20, 'Tenue par un Pokémon, elle soigne tous les problèmes de statut.'],
    ];

    public function run(): void
    {
        foreach (self::ITEMS as $slug => [$name, $pocket, $price, $description]) {
            $item = Item::where('slug', $slug)->first();
            if ($item) {
                // Déjà importé : on garde son nom et son prix. PokéAPI range le Multi Exp
                // dans les objets rares (il s'active pour toute l'équipe depuis la 6e génération) :
                // ici il se tient, comme dans les jeux de la 2e à la 5e génération.
                $item->forceFill([
                    'pocket' => $pocket,
                    'holdable' => true,
                    'tradable' => true,
                    'consumable' => str_ends_with($slug, '-berry'),
                ])->save();
                continue;
            }

            $isBerry = str_ends_with($slug, '-berry');
            Item::forceCreate([
                'slug' => $slug,
                'name' => $name,
                'pocket' => $pocket,
                'category' => $isBerry ? 'medicine' : 'held-items',
                'description' => $description,
                'price' => $price,
                'sell_price' => intdiv($price, 2),
                'consumable' => $isBerry,
                'tradable' => true,
                'usable_in_battle' => false,
                'usable_overworld' => false,
                'holdable' => true,
                // Baie Oran / Sitrus : utilisables aussi depuis le Sac, comme une Potion
                'effect_code' => match ($slug) { 'oran-berry' => 'heal_hp', 'sitrus-berry' => 'heal_hp_percent', default => null },
                'effect_value' => match ($slug) { 'oran-berry' => 10, 'sitrus-berry' => 25, default => null },
            ]);
        }

        // La Bicyclette : un objet rare, qu'on "utilise" pour monter dessus ou descendre
        Item::updateOrCreate(['slug' => 'bicycle'], [
            'pocket' => 'key_items',
            'consumable' => false,
            'tradable' => false,
            'holdable' => false,
            'effect_code' => 'ride',
        ] + (Item::where('slug', 'bicycle')->exists() ? [] : [
            'name' => 'Bicyclette',
            'category' => 'gameplay',
            'description' => 'Une bicyclette pliable. Elle permet de se déplacer bien plus vite qu\'à pied.',
            'price' => 0,
            'sell_price' => 0,
            'usable_overworld' => true,
        ]));
    }
}
