<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sac du personnage. La "poche" (soins, balls, objets rares...) vient de items.pocket,
 * pas besoin de la dupliquer ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);   // max 999 dans le jeu
            $table->boolean('registered')->default(false);          // raccourci (Vélo sur une touche)
            $table->timestamps();
            $table->unique(['character_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
