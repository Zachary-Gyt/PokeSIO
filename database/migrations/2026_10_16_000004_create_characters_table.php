<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personnages : 3 emplacements par compte (users de Laravel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');                    // 1, 2 ou 3
            $table->string('name', 20);
            $table->enum('gender', ['male', 'female']);             // -> choisit le sprite du joueur
            $table->unsignedInteger('trainer_id')->unique();        // ID Dresseur (5 chiffres comme dans le jeu)
            $table->unsignedInteger('money')->default(3000);
            $table->foreignId('starter_species_id')->constrained('pokemon_species');

            // Position dans le monde (sauvegarde).
            // `current_map` = nom du fichier de carte Tiled (ex: "pallet_town" -> maps/pallet_town.tmj)
            // Les positions en temps réel passent par WebSocket ; on n'écrit en base
            // qu'aux changements de carte, sauvegardes et déconnexions.
            $table->string('current_map')->default('pallet_town');
            $table->unsignedSmallInteger('position_x')->default(0); // en tuiles
            $table->unsignedSmallInteger('position_y')->default(0);
            $table->enum('facing', ['up', 'down', 'left', 'right'])->default('down');
            $table->string('last_heal_map')->nullable();            // Centre Pokémon où réapparaître après un K.O.
            $table->unsignedSmallInteger('last_heal_x')->nullable();
            $table->unsignedSmallInteger('last_heal_y')->nullable();

            $table->unsignedInteger('play_time')->default(0);       // en secondes
            $table->json('story_flags')->nullable();                // événements scénario, objets ramassés au sol...
            $table->timestamp('last_seen_at')->nullable();          // présence en ligne
            $table->timestamps();

            $table->unique(['user_id', 'slot']);                    // un seul personnage par emplacement
        });

        // Badges (référence) + badges obtenus
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedTinyInteger('order')->unique();
            $table->string('gym_leader')->nullable();
            $table->string('sprite')->nullable();
            $table->timestamps();
        });

        Schema::create('badge_character', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->timestamp('obtained_at')->useCurrent();
            $table->unique(['character_id', 'badge_id']);
        });

        // Pokédex du personnage : vu / capturé
        Schema::create('pokedex_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pokemon_species_id')->constrained('pokemon_species')->cascadeOnDelete();
            $table->boolean('caught')->default(false);              // ligne présente = vu
            $table->timestamps();
            $table->unique(['character_id', 'pokemon_species_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pokedex_entries');
        Schema::dropIfExists('badge_character');
        Schema::dropIfExists('badges');
        Schema::dropIfExists('characters');
    }
};
