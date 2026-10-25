<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 28 : la TOUR DE COMBAT. Une ligne par personnage : sa meilleure série de victoires (avec l'équipe),
 * pour le classement. La série en cours, elle, est dans characters.story_flags['tower'].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battle_tower_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('best_streak')->default(0);
            $table->json('best_team')->nullable();                 // [{ name, speciesName, slug, icon }]
            $table->timestamp('best_at')->nullable();
            $table->unsignedInteger('total_wins')->default(0);
            $table->unsignedInteger('sets')->default(0);           // séries de 7 terminées
            $table->timestamps();
            $table->index('best_streak');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_tower_records');
    }
};
