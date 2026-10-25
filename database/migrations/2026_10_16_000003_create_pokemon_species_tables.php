<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'ESPÈCE (le "modèle" Pokédex : Bulbizarre en général),
 * à ne pas confondre avec un Pokémon possédé par un joueur (table `pokemon`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pokemon_species', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('pokedex_number')->unique();
            $table->string('slug')->unique();                       // "bulbasaur"
            $table->string('name');                                 // "Bulbizarre"
            $table->unsignedTinyInteger('generation');              // 1 = Kanto
            // Permet d'ouvrir le jeu génération par génération sans réimporter :
            // seules les espèces "obtainable" apparaissent en jeu (rencontres, starters, évolutions).
            $table->boolean('is_obtainable')->default(true);
            $table->string('category')->nullable();                 // "Pokémon Graine"
            $table->text('pokedex_entry')->nullable();
            $table->foreignId('primary_type_id')->constrained('types');
            $table->foreignId('secondary_type_id')->nullable()->constrained('types');

            // Statistiques de base
            $table->unsignedTinyInteger('base_hp');
            $table->unsignedTinyInteger('base_attack');
            $table->unsignedTinyInteger('base_defense');
            $table->unsignedTinyInteger('base_sp_attack');
            $table->unsignedTinyInteger('base_sp_defense');
            $table->unsignedTinyInteger('base_speed');

            // EV donnés quand on le met K.O.
            $table->unsignedTinyInteger('ev_yield_hp')->default(0);
            $table->unsignedTinyInteger('ev_yield_attack')->default(0);
            $table->unsignedTinyInteger('ev_yield_defense')->default(0);
            $table->unsignedTinyInteger('ev_yield_sp_attack')->default(0);
            $table->unsignedTinyInteger('ev_yield_sp_defense')->default(0);
            $table->unsignedTinyInteger('ev_yield_speed')->default(0);

            $table->unsignedSmallInteger('base_experience');        // XP donnée quand vaincu
            $table->enum('growth_rate', ['fast', 'medium_fast', 'medium_slow', 'slow', 'erratic', 'fluctuating']);
            $table->unsignedTinyInteger('capture_rate');            // 3 (légendaire) à 255 (Rattata)
            $table->unsignedTinyInteger('base_friendship')->default(70);
            // -1 = asexué, sinon proportion de femelles en huitièmes (0 = 100% mâle, 8 = 100% femelle)
            $table->tinyInteger('gender_rate')->default(4);
            $table->unsignedSmallInteger('height')->nullable();     // en décimètres
            $table->unsignedSmallInteger('weight')->nullable();     // en hectogrammes
            $table->boolean('is_starter')->default(false);          // proposé à la création du personnage
            $table->boolean('is_legendary')->default(false);

            // Sprites (chemins relatifs au disque "public", ou URL si non téléchargés)
            $table->string('sprite_front')->nullable();             // combat, vue adverse
            $table->string('sprite_back')->nullable();              // combat, vue joueur
            $table->string('sprite_icon')->nullable();              // miniature équipe / PC
            $table->string('sprite_overworld')->nullable();         // sur la carte (suit le joueur) : PAS fourni par PokéAPI
            $table->string('sprite_front_shiny')->nullable();
            $table->string('sprite_back_shiny')->nullable();
            $table->string('cry')->nullable();                      // fichier audio du cri

            $table->timestamps();
        });

        // Talents possibles d'une espèce (1 à 2 + éventuellement un talent caché)
        Schema::create('pokemon_species_ability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pokemon_species_id')->constrained('pokemon_species')->cascadeOnDelete();
            $table->foreignId('ability_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_hidden')->default(false);
            $table->unique(['pokemon_species_id', 'ability_id']);
        });

        // Learnset : quelles attaques une espèce peut apprendre et comment
        Schema::create('pokemon_species_move', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pokemon_species_id')->constrained('pokemon_species')->cascadeOnDelete();
            $table->foreignId('move_id')->constrained()->cascadeOnDelete();
            $table->enum('learn_method', ['level_up', 'tm', 'egg', 'tutor']);
            $table->unsignedTinyInteger('level')->nullable();       // pour level_up (1 = attaque de départ)
            // Noms courts : MySQL limite les noms d'index à 64 caractères
            $table->unique(['pokemon_species_id', 'move_id', 'learn_method'], 'species_move_unique');
            $table->index(['pokemon_species_id', 'learn_method', 'level'], 'species_move_level_index');
        });

        // Évolutions
        Schema::create('evolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_species_id')->constrained('pokemon_species')->cascadeOnDelete();
            $table->foreignId('to_species_id')->constrained('pokemon_species')->cascadeOnDelete();
            $table->enum('trigger', ['level', 'item', 'trade', 'friendship', 'other']);
            $table->unsignedTinyInteger('min_level')->nullable();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete(); // Pierre Feu...
            $table->unsignedTinyInteger('min_friendship')->nullable();
            $table->enum('time_of_day', ['day', 'night'])->nullable();
            $table->unique(['from_species_id', 'to_species_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evolutions');
        Schema::dropIfExists('pokemon_species_move');
        Schema::dropIfExists('pokemon_species_ability');
        Schema::dropIfExists('pokemon_species');
    }
};
