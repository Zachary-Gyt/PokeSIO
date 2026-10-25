<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 15 : un nouveau type de combat, "safari" (Parc Safari).
 * La colonne battles.type était une liste fermée (wild, trainer, pvp) : elle devient un simple texte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('battles', function (Blueprint $table) {
            $table->string('type', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('battles', function (Blueprint $table) {
            $table->enum('type', ['wild', 'trainer', 'pvp'])->change();
        });
    }
};
