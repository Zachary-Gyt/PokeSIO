<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 22 :
 *  - deux poches de plus pour le Sac d'Essentials : "battle" (Objets combat : Attaque +, Garde-Stats...) et "mail" (Lettres) ;
 *  - berry_picks : quand chaque joueur a cueilli chaque buisson à Baies (le buisson repousse après un délai, pour lui seul).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->enum('pocket', ['medicine', 'balls', 'key_items', 'held_items', 'berries', 'tms', 'misc', 'battle', 'mail'])->change();
        });

        Schema::create('berry_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('map_key', 100);
            $table->string('bush', 100);                 // clé du buisson sur la carte (propriété id, ou "x_y")
            $table->timestamp('picked_at');
            $table->unique(['character_id', 'map_key', 'bush']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('berry_picks');
        Schema::table('items', function (Blueprint $table) {
            $table->enum('pocket', ['medicine', 'balls', 'key_items', 'held_items', 'berries', 'tms', 'misc'])->change();
        });
    }
};
