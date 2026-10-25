<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Données de référence "statiques" du jeu (remplies par `php artisan pokeapi:import`) :
 * types, efficacités, natures, talents.
 *
 * `slug` = identifiant PokéAPI en anglais (ex: "fire"), stable et utilisé dans le code.
 * `name` = nom affiché dans la langue du jeu (ex: "Feu").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('color', 7)->nullable();   // #EE8130 pour l'UI
            $table->string('icon')->nullable();
            $table->timestamps();
        });

        // Table des faiblesses/résistances : Eau -> Feu = 2.0
        // Seules les paires différentes de x1 sont stockées.
        Schema::create('type_efficacies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attacking_type_id')->constrained('types')->cascadeOnDelete();
            $table->foreignId('defending_type_id')->constrained('types')->cascadeOnDelete();
            $table->decimal('multiplier', 3, 2);       // 0, 0.5 ou 2
            $table->unique(['attacking_type_id', 'defending_type_id']);
        });

        // Natures : +10 % sur une stat, -10 % sur une autre (null/null = neutre)
        Schema::create('natures', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');                   // Rigide, Timide...
            $table->enum('increased_stat', ['attack', 'defense', 'sp_attack', 'sp_defense', 'speed'])->nullable();
            $table->enum('decreased_stat', ['attack', 'defense', 'sp_attack', 'sp_defense', 'speed'])->nullable();
            $table->timestamps();
        });

        // Talents : Brasier, Torrent, Engrais...
        Schema::create('abilities', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('effect_code')->nullable(); // clé lue par ton moteur de combat (à remplir à la main)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abilities');
        Schema::dropIfExists('natures');
        Schema::dropIfExists('type_efficacies');
        Schema::dropIfExists('types');
    }
};
