<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le monde : rencontres sauvages et dresseurs PNJ.
 *
 * Les cartes elles-mêmes (tuiles, collisions, portes, PNJ placés) vivent dans les
 * fichiers Tiled. La base ne fait que le lien via des clés texte :
 *   - `map_key`  = nom du fichier de carte (ex: "route_1")
 *   - `zone`     = propriété "encounter_zone" d'un rectangle dans un calque d'objets Tiled
 *   - `trainers.slug` = propriété "trainer" d'un objet PNJ dans Tiled
 */
return new class extends Migration
{
    public function up(): void
    {
        // Table de rencontres : "dans l'herbe de la Route 1, Rattata niv. 2-4, poids 45"
        Schema::create('wild_encounters', function (Blueprint $table) {
            $table->id();
            $table->string('map_key');
            $table->string('zone')->default('default');             // plusieurs zones d'herbe sur une même carte
            $table->enum('method', ['grass', 'cave', 'surf', 'old_rod', 'good_rod', 'super_rod', 'headbutt', 'rock_smash']);
            $table->foreignId('pokemon_species_id')->constrained('pokemon_species')->cascadeOnDelete();
            $table->unsignedTinyInteger('min_level');
            $table->unsignedTinyInteger('max_level');
            $table->unsignedSmallInteger('weight');                 // probabilité relative dans la zone
            $table->enum('time_of_day', ['morning', 'day', 'night'])->nullable(); // null = toute la journée
            $table->timestamps();
            $table->index(['map_key', 'zone', 'method']);
        });

        // Dresseurs PNJ (Gamin, Scout, Champions d'arène, Conseil 4, rival...)
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();                       // référencé depuis Tiled : "route1_youngster_joey"
            $table->string('name');                                 // "Joey"
            $table->string('trainer_class');                        // "Gamin"
            $table->string('map_key')->nullable();
            $table->string('sprite_overworld')->nullable();
            $table->string('sprite_battle')->nullable();
            $table->unsignedInteger('reward_money')->default(0);
            $table->text('intro_text')->nullable();                 // "Mes Pokémon sont les meilleurs !"
            $table->text('defeat_text')->nullable();
            $table->text('after_text')->nullable();                 // dialogue une fois battu
            $table->unsignedTinyInteger('sight_range')->default(4); // nb de tuiles avant de repérer le joueur
            $table->unsignedTinyInteger('ai_level')->default(1);    // 1 = attaques au hasard, 3 = stratégique
            $table->boolean('is_double_battle')->default(false);
            $table->boolean('rematchable')->default(false);
            $table->foreignId('badge_id')->nullable()->constrained('badges')->nullOnDelete(); // Champions d'arène
            $table->foreignId('reward_item_id')->nullable()->constrained('items')->nullOnDelete(); // CT donnée par un Champion
            $table->timestamps();
        });

        // Équipe d'un dresseur PNJ (modèle, pas des instances : régénérée à chaque combat)
        Schema::create('trainer_pokemon', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trainer_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');                    // 1..6, ordre d'envoi
            $table->foreignId('pokemon_species_id')->constrained('pokemon_species');
            $table->unsignedTinyInteger('level');
            $table->string('nickname', 12)->nullable();
            $table->foreignId('nature_id')->nullable()->constrained('natures')->nullOnDelete();   // null = aléatoire
            $table->foreignId('ability_id')->nullable()->constrained('abilities')->nullOnDelete(); // null = 1er talent
            $table->foreignId('held_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->unsignedTinyInteger('iv')->default(15);         // même IV sur toutes les stats (difficulté)
            $table->json('evs')->nullable();                        // {"attack":252,"speed":252} pour les dresseurs forts
            $table->json('move_ids')->nullable();                   // [33, 45] ; null = 4 dernières attaques apprises par niveau
            $table->boolean('is_shiny')->default(false);
            $table->unique(['trainer_id', 'slot']);
        });

        // Dresseurs déjà battus par un personnage (ils ne te défient plus)
        Schema::create('character_trainer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('times_defeated')->default(1);
            $table->timestamp('first_defeated_at')->useCurrent();
            $table->timestamp('last_defeated_at')->useCurrent();
            $table->unique(['character_id', 'trainer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_trainer');
        Schema::dropIfExists('trainer_pokemon');
        Schema::dropIfExists('trainers');
        Schema::dropIfExists('wild_encounters');
    }
};
