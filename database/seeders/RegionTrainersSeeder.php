<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use Illuminate\Database\Seeder;

/**
 * Les DRESSEURS DES ROUTES de la région : Route 1, Crémieu (le pêcheur de l'étang), abords du Parc Safari,
 * Bachut et la cour de l'ORT.
 *
 *   php artisan db:seed --class=RegionTrainersSeeder
 *
 * Relançable : tout est retrouvé par son slug. Les PNJ sont déjà placés dans les cartes
 * (propriété Tiled "trainer" = le slug ci-dessous).
 * Argent gagné = reward_money x niveau du Pokémon le plus fort (formule des jeux).
 * Objet offert (reward_item) : donné à la PREMIÈRE victoire seulement.
 */
class RegionTrainersSeeder extends Seeder
{
    /** Classe affichée => [sprite (carte et combat), argent par niveau] */
    private const CLASSES = [
        'Gamin' => ['youngster', 16], 'Fillette' => ['lass', 16], 'Scout' => ['bugcatcher', 16],
        'Campeur' => ['camper', 20], 'Pêcheur' => ['fisherman', 40], 'Pique-Nique' => ['picnicker', 20],
        'Ranger' => ['pokemonranger_m', 60], 'Motard' => ['biker', 20], 'Rockeur' => ['rocker', 24],
        'Gentleman' => ['gentleman', 72], 'Joueur' => ['gambler', 72], 'Intello' => ['supernerd', 24],
        'Kinésiste' => ['psychic_f', 20], 'Karatéka' => ['blackbelt', 24], 'Topdresseuse' => ['cooltrainer_f', 36],
    ];

    public function run(): void
    {
        // [slug, carte, classe, nom, vue, [[espèce, niveau], ...], avant, après, objet offert]
        $trainers = [
            // Route 1 (niveaux 5 à 10) : le premier offre le Cherche VS
            ['route1_gamin_hugo', 'road_1', 'Gamin', 'Hugo', 3, [['rattata', 5], ['pidgey', 6]],
                "Hé ! Toi, là ! Tu as un Pokémon ? Alors on se bat !",
                "J'ai perdu... Tiens, prends ça : avec le Cherche VS, les dresseurs déjà battus voudront une revanche !",
                'vs-seeker'],
            ['route1_fillette_lea', 'road_1', 'Fillette', 'Léa', 3, [['nidoran-f', 7], ['pidgey', 7]],
                "Mes Pokémon sont trop mignons ! Et costauds !", "Mes pauvres petits...", null],
            ['route1_scout_tom', 'road_1', 'Scout', 'Tom', 3, [['caterpie', 8], ['weedle', 8], ['metapod', 9]],
                "J'ai attrapé plein d'Insectes dans les hautes herbes !", "Mes Insectes ont besoin d'entraînement.", null],
            ['route1_campeur_lucas', 'road_1', 'Campeur', 'Lucas', 4, [['nidoran-m', 9], ['sandshrew', 10]],
                "Bachut n'est plus très loin. Mais d'abord, il faut me battre !", "Tu mérites d'arriver à Bachut.", null],

            // L'étang de Crémieu (niveaux 4 à 6) : le pêcheur offre la Canne
            ['cremieu_pecheur_gaston', 'cremieu', 'Pêcheur', 'Gaston', 3, [['magikarp', 4], ['magikarp', 5], ['goldeen', 6]],
                "Ça mord ! ... Ah non, c'est un dresseur. Un combat ?",
                "Bien joué ! Tiens, prends ma vieille Canne : pêche dans l'étang, tu verras !",
                'old-rod'],

            // Les abords du Parc Safari (niveaux 10 à 15)
            ['safari_piquenique_clara', 'cremieu_safari', 'Pique-Nique', 'Clara', 3, [['oddish', 11], ['bellsprout', 11], ['gloom', 13]],
                "Je pique-nique près du Parc Safari. Tu veux te battre ?", "Mon pique-nique est gâché...", null],
            ['safari_ranger_thomas', 'cremieu_safari', 'Ranger', 'Thomas', 3, [['venonat', 13], ['exeggcute', 14]],
                "Je veille sur les Pokémon des environs. Montre-moi que tu les respectes !", "Tu es un bon dresseur.", null],
            ['safari_campeur_bastien', 'cremieu_safari', 'Campeur', 'Bastien', 3, [['paras', 12], ['nidorino', 15]],
                "Le Parc Safari ? J'y ai attrapé mon Nidorino !", "Il faut que je retourne au Safari m'entraîner...", null],

            // Bachut (niveaux 10 à 15)
            ['bachut_gamin_kevin', 'bachut', 'Gamin', 'Kévin', 3, [['rattata', 11], ['spearow', 12]],
                "Je suis le plus fort de Bachut ! Enfin... de ma rue.", "Bon, peut-être pas de ma rue...", null],
            ['bachut_motard_dylan', 'bachut', 'Motard', 'Dylan', 3, [['koffing', 13], ['grimer', 13]],
                "Hé ! Tu regardes ma moto ? Ça va te coûter un combat !", "Ouais, ouais... T'es pas mal.", null],
            ['bachut_rockeur_johnny', 'bachut', 'Rockeur', 'Johnny', 3, [['voltorb', 13], ['magnemite', 14]],
                "Mes Pokémon Électrik font trembler la scène !", "Plus de jus...", null],
            ['bachut_gentleman_edouard', 'bachut', 'Gentleman', 'Édouard', 3, [['growlithe', 14]],
                "Bonjour, jeune dresseur. Un combat entre gens de bonne compagnie ?", "Quelle élégance dans la victoire.", null],
            ['bachut_joueur_fernand', 'bachut', 'Joueur', 'Fernand', 3, [['poliwag', 12], ['horsea', 12], ['psyduck', 13]],
                "Je viens de perdre au Casino. Je me refais sur toi !", "Décidément, ce n'est pas mon jour...", null],

            // La cour de l'ORT (niveaux 15 à 20)
            ['cour_intello_basile', 'ort_courtyard', 'Intello', 'Basile', 3, [['magnemite', 16], ['voltorb', 16], ['electrode', 18]],
                "J'ai calculé toutes les probabilités. Tu vas perdre !", "Il y avait une erreur dans mes calculs...", null],
            ['cour_kinesiste_ines', 'ort_courtyard', 'Kinésiste', 'Inès', 3, [['abra', 17], ['drowzee', 18], ['kadabra', 19]],
                "Je savais que tu passerais par ici...", "Ça, je ne l'avais pas prévu.", null],
            ['cour_karateka_kenji', 'ort_courtyard', 'Karatéka', 'Kenji', 3, [['machop', 18], ['mankey', 18], ['machoke', 20]],
                "Je m'entraîne avant d'aller à la Tour de Combat !", "Je dois encore m'entraîner.", null],
            ['cour_topdresseuse_margaux', 'ort_courtyard', 'Topdresseuse', 'Margaux', 3, [['nidorina', 19], ['pidgeotto', 20]],
                "La Tour de Combat est juste derrière moi. Tu es prêt ?", "Tu es prêt, c'est sûr.", null],
        ];

        foreach ($trainers as [$slug, $map, $class, $name, $sight, $team, $intro, $after, $reward]) {
            [$sprite, $money] = self::CLASSES[$class];
            $rewardId = $reward ? Item::where('slug', $reward)->value('id') : null;
            if ($reward && ! $rewardId) {
                $this->command?->warn("  Objet {$reward} absent de ta base : {$name} ne le donnera pas.");
            }
            $trainer = Trainer::updateOrCreate(['slug' => $slug], [
                'name' => $name, 'trainer_class' => $class, 'map_key' => $map,
                'sprite_overworld' => $sprite, 'sprite_battle' => $sprite, 'reward_money' => $money,
                'sight_range' => $sight, 'ai_level' => $map === 'road_1' || $map === 'cremieu' ? 1 : 2, 'items' => [],
                'intro_text' => $intro, 'defeat_text' => $after, 'after_text' => $after,
                'badge_id' => null, 'reward_item_id' => $rewardId,
            ]);
            $trainer->pokemon()->where('version', 0)->delete();   // les équipes de revanche sont gardées
            $slot = 0;
            foreach ($team as [$species, $level]) {
                $speciesId = PokemonSpecies::where('slug', $species)->value('id');
                if (! $speciesId) {
                    $this->command?->warn("  Espèce inconnue : {$species} ({$slug})");
                    continue;
                }
                $trainer->pokemon()->create(['version' => 0, 'slot' => ++$slot, 'pokemon_species_id' => $speciesId, 'level' => $level]);
            }
            $this->command?->info("{$class} {$name} ({$map}) : {$slot} Pokémon");
        }
    }
}
