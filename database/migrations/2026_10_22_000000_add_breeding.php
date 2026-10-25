<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 18 : la Pension et les Œufs.
 *
 * pokemon_species :
 *   egg_groups   "monster,plant" : les groupes d'Œuf de l'espèce (remplis par BreedingSeeder)
 *   hatch_cycles nombre de cycles avant l'éclosion (1 cycle = 256 pas, voir config/breeding.php)
 *
 * pokemon :
 *   location      devient un simple texte : "party", "box" et maintenant "daycare" (à la Pension)
 *   is_egg        c'est un Œuf (il n'a pas encore éclos)
 *   egg_steps     pas restants avant l'éclosion
 *   daycare_level niveau du Pokémon quand il a été confié à la Pension (pour calculer le prix)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pokemon_species', function (Blueprint $table) {
            $table->string('egg_groups', 40)->nullable()->after('gender_rate');
            $table->unsignedTinyInteger('hatch_cycles')->default(20)->after('egg_groups');
        });

        Schema::table('pokemon', function (Blueprint $table) {
            $table->string('location', 10)->default('party')->change();
            $table->boolean('is_egg')->default(false)->after('nickname');
            $table->unsignedSmallInteger('egg_steps')->nullable()->after('is_egg');
            $table->unsignedTinyInteger('daycare_level')->nullable()->after('box_slot');
        });
    }

    public function down(): void
    {
        Schema::table('pokemon', function (Blueprint $table) {
            $table->dropColumn(['is_egg', 'egg_steps', 'daycare_level']);
        });
        Schema::table('pokemon_species', function (Blueprint $table) {
            $table->dropColumn(['egg_groups', 'hatch_cycles']);
        });
    }
};
