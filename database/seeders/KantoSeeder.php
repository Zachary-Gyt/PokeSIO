<?php

namespace Database\Seeders;

use App\Models\Badge;
use App\Models\Item;
use App\Models\Move;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use Illuminate\Database\Seeder;

/**
 * Kanto : les 8 badges, les CT / CS et les 8 Champions d'arène avec leurs équipes d'origine
 * (Pokémon Rouge et Bleu). Les CT données sont celles de Rouge Feu / Vert Feuille.
 *
 *   php artisan db:seed --class=KantoSeeder
 *
 * Relançable sans risque : tout est retrouvé par son slug (updateOrCreate).
 * Pour placer un Champion sur ta carte : un PNJ dans Tiled avec la propriété trainer = "champion_pierre".
 */
class KantoSeeder extends Seeder
{
    /** CT01 à CT50 (Rouge Feu / Vert Feuille) : numéro => attaque (slug PokéAPI) */
    private const TMS = [
        1 => 'focus-punch', 2 => 'dragon-claw', 3 => 'water-pulse', 4 => 'calm-mind', 5 => 'roar',
        6 => 'toxic', 7 => 'hail', 8 => 'bulk-up', 9 => 'bullet-seed', 10 => 'hidden-power',
        11 => 'sunny-day', 12 => 'taunt', 13 => 'ice-beam', 14 => 'blizzard', 15 => 'hyper-beam',
        16 => 'light-screen', 17 => 'protect', 18 => 'rain-dance', 19 => 'giga-drain', 20 => 'safeguard',
        21 => 'frustration', 22 => 'solar-beam', 23 => 'iron-tail', 24 => 'thunderbolt', 25 => 'thunder',
        26 => 'earthquake', 27 => 'return', 28 => 'dig', 29 => 'psychic', 30 => 'shadow-ball',
        31 => 'brick-break', 32 => 'double-team', 33 => 'reflect', 34 => 'shock-wave', 35 => 'flamethrower',
        36 => 'sludge-bomb', 37 => 'sandstorm', 38 => 'fire-blast', 39 => 'rock-tomb', 40 => 'aerial-ace',
        41 => 'torment', 42 => 'facade', 43 => 'secret-power', 44 => 'rest', 45 => 'attract',
        46 => 'thief', 47 => 'steel-wing', 48 => 'skill-swap', 49 => 'snatch', 50 => 'overheat',
    ];

    /** CS01 à CS08 */
    private const HMS = [
        1 => 'cut', 2 => 'fly', 3 => 'surf', 4 => 'strength', 5 => 'flash', 6 => 'rock-smash', 7 => 'waterfall', 8 => 'dive',
    ];

    public function run(): void
    {
        $this->machines();
        $this->badges();
        $this->leaders();
    }

    // ---------------------------------------------------------------------
    // CT et CS : des objets de la poche "CT et CS", réutilisables à l'infini (comme dans les jeux récents)
    // ---------------------------------------------------------------------

    private function machines(): void
    {
        $created = 0;
        foreach (['tm' => self::TMS, 'hm' => self::HMS] as $kind => $list) {
            foreach ($list as $number => $moveSlug) {
                $move = Move::where('slug', $moveSlug)->first();
                if (! $move) {
                    // L'attaque n'a pas été importée (aucun Pokémon de 1re génération ne l'apprend) : CT ignorée
                    $this->command->warn(strtoupper($kind) . sprintf('%02d', $number) . " : attaque {$moveSlug} absente de la table moves, CT ignorée.");
                    continue;
                }
                $label = ($kind === 'tm' ? 'CT' : 'CS') . sprintf('%02d', $number);
                Item::updateOrCreate(['slug' => $kind . sprintf('%02d', $number)], [
                    'name' => "{$label} {$move->name}",
                    'pocket' => 'tms',
                    'category' => $kind === 'tm' ? 'all-machines' : 'hidden-machines',
                    'description' => "Apprend la capacité {$move->name} à un Pokémon compatible. Elle peut être utilisée autant de fois que vous le voulez.",
                    'price' => 0,
                    'sell_price' => 0,
                    'consumable' => false,
                    'tradable' => $kind === 'tm',
                    'usable_in_battle' => false,
                    'usable_overworld' => true,
                    'holdable' => false,
                    'effect_code' => 'teach',
                    'effect_value' => null,
                    'move_id' => $move->id,
                ]);
                $created++;
            }
        }
        $this->command->info("{$created} CT / CS.");
    }

    // ---------------------------------------------------------------------
    // Badges : "sprite" = numéro de l'icône dans Graphics/UI/Trainer Card/icon_badges.png (0 à 7)
    // ---------------------------------------------------------------------

    private const BADGES = [
        1 => ['Badge Roche', 'Pierre'],
        2 => ['Badge Cascade', 'Ondine'],
        3 => ['Badge Foudre', 'Major Bob'],
        4 => ['Badge Prisme', 'Érika'],
        5 => ['Badge Âme', 'Koga'],
        6 => ['Badge Marais', 'Morgane'],
        7 => ['Badge Volcan', 'Auguste'],
        8 => ['Badge Terre', 'Giovanni'],
    ];

    private function badges(): void
    {
        foreach (self::BADGES as $order => [$name, $leader]) {
            Badge::updateOrCreate(['order' => $order], ['name' => $name, 'gym_leader' => $leader, 'sprite' => (string) ($order - 1)]);
        }
        $this->command->info('8 badges.');
    }

    // ---------------------------------------------------------------------
    // Champions d'arène : équipes de Pokémon Rouge / Bleu
    // ---------------------------------------------------------------------

    private function leaders(): void
    {
        $leaders = [
            [1, 'champion_pierre', 'Pierre', 'leader_brock', 'tm39', ['super-potion'],
                "Je suis Pierre, le Champion de l'Arène d'Argenta.\n\nMa volonté est aussi solide que la roche. Mes Pokémon aussi !\n\nVoyons si tu peux entamer ma défense !",
                "Je t'ai sous-estimé...\nTu mérites le Badge Roche !",
                "Le monde est vaste. Continue ton voyage et deviens plus fort !",
                [['geodude', 12], ['onix', 14]]],
            [2, 'champion_ondine', 'Ondine', 'leader_misty', 'tm03', ['super-potion'],
                "Salut ! Je suis Ondine, la Championne d'Azuria.\n\nMa stratégie ? Les Pokémon Eau, à fond !\n\nPrêt à te faire submerger ?",
                "Bravo... Tu as gagné le Badge Cascade !",
                "Un jour, je serai la meilleure dresseuse de Pokémon Eau du monde !",
                [['staryu', 18], ['starmie', 21]]],
            [3, 'champion_major_bob', 'Major Bob', 'leader_surge', 'tm34', ['super-potion'],
                "Hé, petit ! Bienvenue dans l'Arène de Carmin !\n\nMes Pokémon Électrik m'ont sauvé la peau plus d'une fois.\n\nTu vas griller sur place !",
                "Wow ! Tu as de l'énergie à revendre !\nVoici le Badge Foudre !",
                "Un vrai soldat sait reconnaître un adversaire de valeur !",
                [['voltorb', 21], ['pikachu', 18], ['raichu', 24]]],
            [4, 'champion_erika', 'Érika', 'leader_erika', 'tm19', ['hyper-potion'],
                "Bonjour... Quel temps agréable, n'est-ce pas ?\n\nJe suis Érika, la Championne de Céladopole. J'aime les fleurs et les Pokémon Plante.\n\nMontrez-moi ce que vous valez.",
                "Oh ! J'ai perdu...\nPrenez ce Badge Prisme, vous l'avez mérité.",
                "Les fleurs nous apprennent la patience. Et vous m'avez appris l'humilité.",
                [['victreebel', 29], ['tangela', 24], ['vileplume', 29]]],
            [5, 'champion_koga', 'Koga', 'leader_koga', 'tm06', ['hyper-potion', 'hyper-potion'],
                "Fwahahaha ! Un enfant ose me défier ?\n\nJe suis Koga, le Champion de Parmanie. Le poison est l'art des ninjas.\n\nTu vas découvrir la douleur des techniques empoisonnées !",
                "Humph ! Tu as prouvé ta valeur.\nVoici le Badge Âme !",
                "Un ninja accepte la défaite et en tire des leçons.",
                [['koffing', 37], ['muk', 39], ['koffing', 37], ['weezing', 43]]],
            [6, 'champion_morgane', 'Morgane', 'leader_sabrina', 'tm04', ['hyper-potion', 'hyper-potion'],
                "Je savais que tu viendrais.\n\nJe suis Morgane, Championne de Safrania. Mes pouvoirs psychiques m'ont annoncé ta visite.\n\nMais pas l'issue de ce combat...",
                "Je ne l'avais pas prévu...\nLe Badge Marais est à toi.",
                "Mes visions sont parfois troubles. Toi, tu vois clair.",
                [['kadabra', 38], ['mr-mime', 37], ['venomoth', 38], ['alakazam', 43]]],
            [7, 'champion_auguste', 'Auguste', 'leader_blaine', 'tm38', ['hyper-potion', 'hyper-potion'],
                "Ouaaah ! Je suis Auguste, le Champion brûlant de Cramois'Île !\n\nMes Pokémon Feu vont te réduire en cendres !\n\nAs-tu pensé à prendre de l'Anti-Brûle ?",
                "Je me suis consumé tout seul !\nVoici le Badge Volcan !",
                "Une question pour la route : qu'est-ce qui brûle sans jamais s'éteindre ? La passion !",
                [['growlithe', 42], ['ponyta', 40], ['rapidash', 42], ['arcanine', 47]]],
            [8, 'champion_giovanni', 'Giovanni', 'leader_giovanni', 'tm26', ['hyper-potion', 'hyper-potion'],
                "Ainsi, tu es arrivé jusqu'ici.\n\nJe suis Giovanni, le Champion de Jadielle. Cette arène est la dernière avant la Ligue.\n\nPrépare-toi à affronter le plus fort des Champions !",
                "Ha ! C'était un combat remarquable.\nTu as gagné le Badge Terre.",
                "Avec ce badge, plus aucun Pokémon ne te désobéira.",
                [['rhyhorn', 45], ['dugtrio', 42], ['nidoqueen', 44], ['nidoking', 45], ['rhydon', 50]]],
        ];

        foreach ($leaders as [$order, $slug, $name, $sprite, $tm, $items, $intro, $defeat, $after, $team]) {
            $this->trainer([
                'slug' => $slug,
                'name' => $name,
                'trainer_class' => 'Champion',
                'map_key' => null,                  // le Champion peut être placé sur n'importe quelle carte
                'sprite_overworld' => $sprite,      // public/game/sprites/trainers/overworld/leader_brock.png
                'sprite_battle' => $sprite,         // public/game/sprites/trainers/battle/leader_brock.png
                'reward_money' => 100,              // x niveau le plus haut : 1 400 ₽ pour Pierre
                'sight_range' => 0,                 // un Champion attend qu'on vienne lui parler
                'ai_level' => 3,                    // choisit ses attaques et se soigne
                'items' => $items,
                'intro_text' => $intro,
                'defeat_text' => $defeat,
                'after_text' => $after,
                'badge_id' => Badge::where('order', $order)->value('id'),
                'reward_item_id' => Item::where('slug', $tm)->value('id'),
            ], $team, iv: 15 + 2 * $order);
        }
    }

    /** Crée ou met à jour un dresseur et remplace son équipe : $team = [[espèce, niveau], ...] */
    private function trainer(array $data, array $team, int $iv = 15): void
    {
        $trainer = Trainer::updateOrCreate(['slug' => $data['slug']], $data);
        $trainer->pokemon()->delete();

        foreach ($team as $index => [$speciesSlug, $level]) {
            $speciesId = PokemonSpecies::where('slug', $speciesSlug)->value('id');
            if (! $speciesId) {
                $this->command->warn("Espèce inconnue : {$speciesSlug} (dresseur {$data['slug']})");
                continue;
            }
            $trainer->pokemon()->create(['slot' => $index + 1, 'pokemon_species_id' => $speciesId, 'level' => $level, 'iv' => $iv]);
        }

        $this->command->info("{$data['trainer_class']} {$data['name']} : " . count($team) . ' Pokémon');
    }
}
