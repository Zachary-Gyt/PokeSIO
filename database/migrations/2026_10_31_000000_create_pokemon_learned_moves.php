<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les capacités qu'un Pokémon a apprises au moins une fois (par niveau, CT, CS, Œuf, Gribouille...).
 * Le Résumé (page Capacités) permet de les reprendre à tout moment, même après les avoir oubliées.
 * Les capacités connues aujourd'hui sont recopiées : rien n'est perdu pour les Pokémon existants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pokemon_learned_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pokemon_id')->constrained('pokemon')->cascadeOnDelete();
            $table->foreignId('move_id')->constrained('moves')->cascadeOnDelete();
            $table->unique(['pokemon_id', 'move_id']);
        });

        DB::table('pokemon_learned_moves')->insertUsing(
            ['pokemon_id', 'move_id'],
            DB::table('pokemon_moves')->select('pokemon_id', 'move_id')->distinct()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pokemon_learned_moves');
    }
};
