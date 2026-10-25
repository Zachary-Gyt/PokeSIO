<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pokémon POSSÉDÉS par un personnage (instances), qu'ils soient dans l'équipe ou au PC.
 * Les stats finales (Attaque, PV max...) ne sont PAS stockées : elles se calculent
 * à partir des stats de base de l'espèce + IV + EV + niveau + nature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pokemon', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pokemon_species_id')->constrained('pokemon_species');
            $table->string('nickname', 12)->nullable();

            // Emplacement : équipe (slot 1-6) ou PC (boîte + case)
            $table->enum('location', ['party', 'box'])->default('party');
            $table->unsignedTinyInteger('party_slot')->nullable();  // 1..6 si location = party
            $table->unsignedTinyInteger('box_number')->nullable();  // 1..N si location = box
            $table->unsignedTinyInteger('box_slot')->nullable();    // 1..30

            // Progression
            $table->unsignedTinyInteger('level')->default(5);
            $table->unsignedInteger('experience')->default(0);
            $table->unsignedTinyInteger('friendship')->default(70);

            // Identité génétique
            $table->enum('gender', ['male', 'female', 'genderless']);
            $table->boolean('is_shiny')->default(false);
            $table->foreignId('nature_id')->constrained('natures');
            $table->foreignId('ability_id')->constrained('abilities');

            // IV : 0-31, tirés aléatoirement à la rencontre
            $table->unsignedTinyInteger('iv_hp');
            $table->unsignedTinyInteger('iv_attack');
            $table->unsignedTinyInteger('iv_defense');
            $table->unsignedTinyInteger('iv_sp_attack');
            $table->unsignedTinyInteger('iv_sp_defense');
            $table->unsignedTinyInteger('iv_speed');

            // EV : 0-252 par stat, 510 au total, gagnés en combat
            $table->unsignedTinyInteger('ev_hp')->default(0);
            $table->unsignedTinyInteger('ev_attack')->default(0);
            $table->unsignedTinyInteger('ev_defense')->default(0);
            $table->unsignedTinyInteger('ev_sp_attack')->default(0);
            $table->unsignedTinyInteger('ev_sp_defense')->default(0);
            $table->unsignedTinyInteger('ev_speed')->default(0);

            // État courant
            $table->unsignedSmallInteger('current_hp');
            $table->enum('status', ['none', 'burn', 'freeze', 'paralysis', 'poison', 'bad_poison', 'sleep'])->default('none');
            $table->unsignedTinyInteger('sleep_turns')->default(0);
            $table->foreignId('held_item_id')->nullable()->constrained('items')->nullOnDelete();

            // Origine (affichée dans la fiche du Pokémon)
            $table->unsignedInteger('original_trainer_id');         // trainer_id du dresseur d'origine
            $table->string('original_trainer_name', 20);
            $table->foreignId('caught_ball_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('met_location')->nullable();
            $table->unsignedTinyInteger('met_level')->nullable();
            $table->timestamp('met_at')->useCurrent();

            $table->timestamps();

            // Empêche 2 Pokémon sur le même slot d'équipe ou la même case de PC.
            // (NULL n'entre pas en conflit dans un index unique, donc ça marche pour les deux cas.)
            $table->unique(['character_id', 'party_slot']);
            $table->unique(['character_id', 'box_number', 'box_slot']);
            $table->index(['character_id', 'location']);
        });

        // Les 4 attaques connues par un Pokémon
        Schema::create('pokemon_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pokemon_id')->constrained('pokemon')->cascadeOnDelete();
            $table->foreignId('move_id')->constrained();
            $table->unsignedTinyInteger('slot');                    // 1..4
            $table->unsignedTinyInteger('current_pp');
            $table->unsignedTinyInteger('pp_ups')->default(0);      // 0..3 (PP Plus)
            $table->unique(['pokemon_id', 'slot']);
            $table->unique(['pokemon_id', 'move_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pokemon_moves');
        Schema::dropIfExists('pokemon');
    }
};
