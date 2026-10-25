<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 23 : les méthodes d'évolution des générations 2 à 4 (les colonnes vides = pas de condition).
 *   held_item_id      objet tenu en montant de niveau (Scorplane : Croc Rasoir la nuit) ou en échange (Onix : Peau Métal)
 *   known_move_id     capacité connue (Mammochon : Pouvoir Antique)
 *   gender            sexe (Gallame : mâle + Pierre Aube)
 *   location          lieu spécial (magnetic, moss, ice) : propriété Tiled "evolution_area" de la carte
 *   party_species_id  Pokémon présent dans l'équipe (Démanta : Rémoraid)
 *   relative_stats    Debugant : 1 = Attaque > Défense, -1 = Attaque < Défense, 0 = égales
 *   trigger "shed"    Munja apparaît quand Ningale évolue (place dans l'équipe + une Poké Ball)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evolutions', function (Blueprint $table) {
            $table->enum('trigger', ['level', 'item', 'trade', 'friendship', 'shed', 'other'])->change();
            $table->foreignId('held_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('known_move_id')->nullable()->constrained('moves')->nullOnDelete();
            $table->foreignId('known_move_type_id')->nullable()->constrained('types')->nullOnDelete();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->string('location', 50)->nullable();
            $table->foreignId('party_species_id')->nullable()->constrained('pokemon_species')->nullOnDelete();
            $table->tinyInteger('relative_stats')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('evolutions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('held_item_id');
            $table->dropConstrainedForeignId('known_move_id');
            $table->dropConstrainedForeignId('known_move_type_id');
            $table->dropConstrainedForeignId('party_species_id');
            $table->dropColumn(['gender', 'location', 'relative_stats']);
            $table->enum('trigger', ['level', 'item', 'trade', 'friendship', 'other'])->change();
        });
    }
};
