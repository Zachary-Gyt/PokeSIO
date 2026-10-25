<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 26 : les REVANCHES.
 *   trainer_pokemon.version          0 = l'équipe du premier combat ; 1, 2, 3... = les équipes de revanche
 *                                    (débloquées par le nombre de badges, voir config/rematches.php)
 *   character_trainer.rematch_ready  le dresseur a levé la main au Cherche VS : il veut une revanche
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainer_pokemon', function (Blueprint $table) {
            $table->unsignedTinyInteger('version')->default(0)->after('trainer_id');
        });
        // Un emplacement (slot) par version : l'ancienne règle "un slot par dresseur" devient "un slot par équipe"
        Schema::table('trainer_pokemon', function (Blueprint $table) {
            $table->unique(['trainer_id', 'version', 'slot']);
        });
        Schema::table('trainer_pokemon', function (Blueprint $table) {
            $table->dropUnique(['trainer_id', 'slot']);
        });
        Schema::table('character_trainer', function (Blueprint $table) {
            $table->boolean('rematch_ready')->default(false)->after('times_defeated');
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('trainer_pokemon')->where('version', '>', 0)->delete();
        Schema::table('trainer_pokemon', function (Blueprint $table) {
            $table->unique(['trainer_id', 'slot']);
        });
        Schema::table('trainer_pokemon', function (Blueprint $table) {
            $table->dropUnique(['trainer_id', 'version', 'slot']);
        });
        Schema::table('trainer_pokemon', function (Blueprint $table) {
            $table->dropColumn('version');
        });
        Schema::table('character_trainer', function (Blueprint $table) {
            $table->dropColumn('rematch_ready');
        });
    }
};
