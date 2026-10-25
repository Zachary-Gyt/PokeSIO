<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\PokemonSpecies;
use Illuminate\Database\Seeder;

/**
 * Étape 18 : ce dont la Pension a besoin en base.
 *
 *   php artisan db:seed --class=BreedingSeeder
 *
 * 1. Les groupes d'Œuf et le nombre de cycles d'éclosion des 151 Pokémon de Kanto
 *    (données de PokéAPI : pokemon_egg_groups.csv et pokemon_species.csv, colonne hatch_counter).
 *    Les espèces importées plus tard par game:import-pokeapi les reçoivent directement.
 * 2. Le Charme Ovale (objet rare) et le Nœud Destin (objet tenu). La Pierre Stase existe déjà (étape 14).
 *
 * Le seeder peut être relancé sans risque.
 */
class BreedingSeeder extends Seeder
{
    /** slug => [groupes d'Œuf, cycles d'éclosion] */
    private const SPECIES = [
        'bulbasaur' => ['monster,plant', 20], 'ivysaur' => ['monster,plant', 20], 'venusaur' => ['monster,plant', 20],
        'charmander' => ['monster,dragon', 20], 'charmeleon' => ['monster,dragon', 20], 'charizard' => ['monster,dragon', 20],
        'squirtle' => ['monster,water1', 20], 'wartortle' => ['monster,water1', 20], 'blastoise' => ['monster,water1', 20],
        'caterpie' => ['bug', 15], 'metapod' => ['bug', 15], 'butterfree' => ['bug', 15],
        'weedle' => ['bug', 15], 'kakuna' => ['bug', 15], 'beedrill' => ['bug', 15],
        'pidgey' => ['flying', 15], 'pidgeotto' => ['flying', 15], 'pidgeot' => ['flying', 15],
        'rattata' => ['ground', 15], 'raticate' => ['ground', 15], 'spearow' => ['flying', 15],
        'fearow' => ['flying', 15], 'ekans' => ['ground,dragon', 20], 'arbok' => ['ground,dragon', 20],
        'pikachu' => ['ground,fairy', 10], 'raichu' => ['ground,fairy', 10], 'sandshrew' => ['ground', 20],
        'sandslash' => ['ground', 20], 'nidoran-f' => ['monster,ground', 20], 'nidorina' => ['no-eggs', 20],
        'nidoqueen' => ['no-eggs', 20], 'nidoran-m' => ['monster,ground', 20], 'nidorino' => ['monster,ground', 20],
        'nidoking' => ['monster,ground', 20], 'clefairy' => ['fairy', 10], 'clefable' => ['fairy', 10],
        'vulpix' => ['ground', 20], 'ninetales' => ['ground', 20], 'jigglypuff' => ['fairy', 10],
        'wigglytuff' => ['fairy', 10], 'zubat' => ['flying', 15], 'golbat' => ['flying', 15],
        'oddish' => ['plant', 20], 'gloom' => ['plant', 20], 'vileplume' => ['plant', 20],
        'paras' => ['bug,plant', 20], 'parasect' => ['bug,plant', 20], 'venonat' => ['bug', 20],
        'venomoth' => ['bug', 20], 'diglett' => ['ground', 20], 'dugtrio' => ['ground', 20],
        'meowth' => ['ground', 20], 'persian' => ['ground', 20], 'psyduck' => ['water1,ground', 20],
        'golduck' => ['water1,ground', 20], 'mankey' => ['ground', 20], 'primeape' => ['ground', 20],
        'growlithe' => ['ground', 20], 'arcanine' => ['ground', 20], 'poliwag' => ['water1', 20],
        'poliwhirl' => ['water1', 20], 'poliwrath' => ['water1', 20], 'abra' => ['humanshape', 20],
        'kadabra' => ['humanshape', 20], 'alakazam' => ['humanshape', 20], 'machop' => ['humanshape', 20],
        'machoke' => ['humanshape', 20], 'machamp' => ['humanshape', 20], 'bellsprout' => ['plant', 20],
        'weepinbell' => ['plant', 20], 'victreebel' => ['plant', 20], 'tentacool' => ['water3', 20],
        'tentacruel' => ['water3', 20], 'geodude' => ['mineral', 15], 'graveler' => ['mineral', 15],
        'golem' => ['mineral', 15], 'ponyta' => ['ground', 20], 'rapidash' => ['ground', 20],
        'slowpoke' => ['monster,water1', 20], 'slowbro' => ['monster,water1', 20], 'magnemite' => ['mineral', 20],
        'magneton' => ['mineral', 20], 'farfetchd' => ['flying,ground', 20], 'doduo' => ['flying', 20],
        'dodrio' => ['flying', 20], 'seel' => ['water1,ground', 20], 'dewgong' => ['water1,ground', 20],
        'grimer' => ['indeterminate', 20], 'muk' => ['indeterminate', 20], 'shellder' => ['water3', 20],
        'cloyster' => ['water3', 20], 'gastly' => ['indeterminate', 20], 'haunter' => ['indeterminate', 20],
        'gengar' => ['indeterminate', 20], 'onix' => ['mineral', 25], 'drowzee' => ['humanshape', 20],
        'hypno' => ['humanshape', 20], 'krabby' => ['water3', 20], 'kingler' => ['water3', 20],
        'voltorb' => ['mineral', 20], 'electrode' => ['mineral', 20], 'exeggcute' => ['plant', 20],
        'exeggutor' => ['plant', 20], 'cubone' => ['monster', 20], 'marowak' => ['monster', 20],
        'hitmonlee' => ['humanshape', 25], 'hitmonchan' => ['humanshape', 25], 'lickitung' => ['monster', 20],
        'koffing' => ['indeterminate', 20], 'weezing' => ['indeterminate', 20], 'rhyhorn' => ['monster,ground', 20],
        'rhydon' => ['monster,ground', 20], 'chansey' => ['fairy', 40], 'tangela' => ['plant', 20],
        'kangaskhan' => ['monster', 20], 'horsea' => ['water1,dragon', 20], 'seadra' => ['water1,dragon', 20],
        'goldeen' => ['water2', 20], 'seaking' => ['water2', 20], 'staryu' => ['water3', 20],
        'starmie' => ['water3', 20], 'mr-mime' => ['humanshape', 25], 'scyther' => ['bug', 25],
        'jynx' => ['humanshape', 25], 'electabuzz' => ['humanshape', 25], 'magmar' => ['humanshape', 25],
        'pinsir' => ['bug', 25], 'tauros' => ['ground', 20], 'magikarp' => ['water2,dragon', 5],
        'gyarados' => ['water2,dragon', 5], 'lapras' => ['monster,water1', 40], 'ditto' => ['ditto', 20],
        'eevee' => ['ground', 35], 'vaporeon' => ['ground', 35], 'jolteon' => ['ground', 35],
        'flareon' => ['ground', 35], 'porygon' => ['mineral', 20], 'omanyte' => ['water1,water3', 30],
        'omastar' => ['water1,water3', 30], 'kabuto' => ['water1,water3', 30], 'kabutops' => ['water1,water3', 30],
        'aerodactyl' => ['flying', 35], 'snorlax' => ['monster', 40], 'articuno' => ['no-eggs', 80],
        'zapdos' => ['no-eggs', 80], 'moltres' => ['no-eggs', 80], 'dratini' => ['water1,dragon', 40],
        'dragonair' => ['water1,dragon', 40], 'dragonite' => ['water1,dragon', 40], 'mewtwo' => ['no-eggs', 120],
        'mew' => ['no-eggs', 120],
    ];

    public function run(): void
    {
        $count = 0;
        foreach (self::SPECIES as $slug => [$groups, $cycles]) {
            $count += PokemonSpecies::where('slug', $slug)->update(['egg_groups' => $groups, 'hatch_cycles' => $cycles]);
        }
        $this->command->info("Groupes d'Œuf : {$count} espèces");

        // Le Charme Ovale : dans le Sac, la Pension produit des Œufs plus souvent
        $this->upsert('oval-charm', [
            'pocket' => 'key_items', 'consumable' => false, 'tradable' => false, 'holdable' => false,
        ], [
            'name' => 'Charme Ovale', 'category' => 'gameplay', 'price' => 0, 'sell_price' => 0,
            'description' => 'Un charme ovale porte-bonheur. Les Pokémon confiés à la Pension pondent des Œufs plus souvent.',
        ]);

        // Le Nœud Destin : tenu par un parent, il transmet 5 IV au lieu de 3
        $this->upsert('destiny-knot', [
            'pocket' => 'held_items', 'consumable' => false, 'tradable' => true, 'holdable' => true,
        ], [
            'name' => 'Nœud Destin', 'category' => 'held-items', 'price' => 0, 'sell_price' => 0,
            'description' => 'Un long fil rouge. Tenu par un Pokémon confié à la Pension, il transmet 5 de ses IV ou de ceux de l\'autre parent.',
        ]);

        // La Pierre Stase : tenue par un parent, elle transmet sa nature
        Item::where('slug', 'everstone')->update(['holdable' => true]);

        $this->command->info('Charme Ovale et Nœud Destin prêts');
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
