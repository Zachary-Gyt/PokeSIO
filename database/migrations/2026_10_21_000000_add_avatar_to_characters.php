<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 17 : le personnage choisi à la création (red, leaf, brendan, may : config/avatars.php).
 * Les personnages existants gardent leur apparence : garçon -> red, fille -> leaf.
 * Le Maître de l'ORT (title_holders) garde aussi l'apparence du joueur qui a pris le titre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('avatar', 30)->default('red')->after('gender');
        });
        DB::table('characters')->where('gender', 'female')->update(['avatar' => 'leaf']);

        Schema::table('title_holders', function (Blueprint $table) {
            $table->string('avatar', 30)->nullable()->after('gender');
        });
        DB::table('title_holders')->where('gender', 'male')->update(['avatar' => 'red']);
        DB::table('title_holders')->where('gender', 'female')->update(['avatar' => 'leaf']);
    }

    public function down(): void
    {
        Schema::table('characters', fn (Blueprint $table) => $table->dropColumn('avatar'));
        Schema::table('title_holders', fn (Blueprint $table) => $table->dropColumn('avatar'));
    }
};
