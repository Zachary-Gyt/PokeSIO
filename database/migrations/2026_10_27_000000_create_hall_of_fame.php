<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 27 : le PANTHÉON. Une ligne par victoire contre le Maître de la Ligue, avec la "photo" de l'équipe
 * à ce moment-là (les Pokémon peuvent évoluer, être relâchés... l'entrée, elle, ne change plus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_of_fame_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');           // 1re, 2e... entrée de ce personnage
            $table->string('trainer_name', 30);
            $table->string('avatar', 30)->nullable();
            $table->json('team');                             // [{ speciesId, slug, name, speciesName, level, gender, shiny, sprite, icon }]
            $table->unsignedInteger('play_time')->default(0); // temps de jeu au moment de la victoire (secondes)
            $table->timestamp('entered_at')->useCurrent();
            $table->index(['character_id', 'number']);
            $table->index('entered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_of_fame_entries');
    }
};
