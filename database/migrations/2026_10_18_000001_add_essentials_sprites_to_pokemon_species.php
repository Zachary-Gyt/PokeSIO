<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprites de Pokémon Essentials : variante femelle, Pokémon "suiveur" chromatique, empreinte.
 * Remplies par `php artisan pokemon:essentials-sprites`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pokemon_species', function (Blueprint $table) {
            // Vrai si l'espèce a des sprites de combat différents pour les femelles (Pikachu, Rattata...)
            $table->boolean('has_female_sprites')->default(false)->after('sprite_back_shiny');
            $table->string('sprite_overworld_shiny')->nullable()->after('sprite_overworld');
            $table->string('sprite_footprint')->nullable()->after('sprite_icon');
        });
    }

    public function down(): void
    {
        Schema::table('pokemon_species', function (Blueprint $table) {
            $table->dropColumn(['has_female_sprites', 'sprite_overworld_shiny', 'sprite_footprint']);
        });
    }
};
