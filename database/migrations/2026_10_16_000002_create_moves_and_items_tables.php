<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attaques et objets (données de référence, remplies par l'import PokéAPI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moves', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();                       // "thunderbolt"
            $table->string('name');                                 // "Tonnerre"
            $table->unsignedTinyInteger('generation');              // génération d'apparition
            $table->foreignId('type_id')->constrained('types');
            $table->enum('category', ['physical', 'special', 'status']);
            $table->unsignedSmallInteger('power')->nullable();      // null pour les attaques de statut
            $table->unsignedTinyInteger('accuracy')->nullable();    // null = ne rate jamais
            $table->unsignedTinyInteger('pp');
            $table->tinyInteger('priority')->default(0);            // Vive-Attaque = +1
            $table->string('target')->default('selected-pokemon');  // cible PokéAPI
            $table->boolean('makes_contact')->default(false);
            $table->string('effect_code')->nullable();              // ex: 'damage+ailment', 'net-good-stats'
            $table->unsignedTinyInteger('effect_chance')->nullable(); // % de chance de l'effet secondaire
            // Détails pour le moteur de combat : statut infligé, changements de stats,
            // drain, soin, taux de critique, nombre de coups, recul...
            $table->json('meta')->nullable();
            $table->text('description')->nullable();
            $table->string('animation')->nullable();                // animation de combat
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();                       // "super-potion"
            $table->string('name');                                 // "Super Potion"
            // Poche de l'inventaire (comme dans le sac du jeu)
            $table->enum('pocket', ['medicine', 'balls', 'key_items', 'held_items', 'berries', 'tms', 'misc']);
            $table->string('category')->nullable();                 // catégorie PokéAPI (healing, standard-balls...)
            $table->text('description')->nullable();
            $table->string('sprite')->nullable();
            $table->unsignedInteger('price')->default(0);           // prix d'achat au shop (0 = non vendu)
            $table->unsignedInteger('sell_price')->default(0);
            $table->boolean('consumable')->default(true);           // false pour Vélo, Canne...
            $table->boolean('tradable')->default(true);             // false pour les objets rares
            $table->boolean('usable_in_battle')->default(false);
            $table->boolean('usable_overworld')->default(false);
            $table->boolean('holdable')->default(false);            // peut être tenu par un Pokémon
            $table->string('effect_code')->nullable();              // 'heal_hp', 'cure_status', 'catch', 'evolve', 'fish'...
            $table->integer('effect_value')->nullable();            // 20 PV pour une Potion, x15 (=1.5) pour Super Ball...
            $table->foreignId('move_id')->nullable()->constrained('moves')->nullOnDelete(); // pour les CT/CS
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
        Schema::dropIfExists('moves');
    }
};
