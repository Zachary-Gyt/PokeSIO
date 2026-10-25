<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 20 : les JETONS du Casino, une monnaie à part (9 999 au plus, voir config/game_corner.php).
 * Comme l'argent, ils ne changent que par le serveur (CoinService), jamais par une valeur envoyée par le navigateur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('coins')->default(0)->after('money');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('coins');
        });
    }
};
