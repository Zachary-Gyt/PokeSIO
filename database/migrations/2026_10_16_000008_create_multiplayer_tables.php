<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multijoueur : échanges et combats.
 *
 * Ce qui n'est PAS en base : les positions des joueurs en temps réel.
 * Elles passent par WebSocket (Laravel Reverb + un canal de présence par carte, "map.route_1").
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- ÉCHANGES ---------------------------------------------------------
        // Déroulé : pending (invitation) -> negotiating (chacun ajoute ses offres)
        // -> les deux cochent "confirmé" -> completed (transfert en transaction).
        // Toute modification d'offre remet les deux confirmations à false.
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('initiator_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('characters')->cascadeOnDelete();
            $table->enum('status', ['pending', 'negotiating', 'completed', 'declined', 'cancelled'])->default('pending');
            $table->unsignedInteger('initiator_money')->default(0);
            $table->unsignedInteger('recipient_money')->default(0);
            $table->boolean('initiator_confirmed')->default(false);
            $table->boolean('recipient_confirmed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['initiator_id', 'status']);
            $table->index(['recipient_id', 'status']);
        });

        Schema::create('trade_pokemon', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pokemon_id')->constrained('pokemon')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('characters')->cascadeOnDelete(); // qui le propose
            $table->unique(['trade_id', 'pokemon_id']);
        });

        Schema::create('trade_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('characters')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->unique(['trade_id', 'owner_id', 'item_id']);
        });

        // ---- COMBATS ----------------------------------------------------------
        // Une seule table pour les 3 types de combat. L'état complet (PV en cours,
        // modificateurs de stats, météo...) est un snapshot JSON : le combat ne
        // touche aux vrais Pokémon qu'à la fin (sauf en PvP, où rien n'est modifié).
        Schema::create('battles', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['wild', 'trainer', 'pvp']);
            $table->enum('status', ['pending', 'active', 'finished', 'abandoned'])->default('active');
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();             // joueur 1
            $table->foreignId('opponent_character_id')->nullable()->constrained('characters')->cascadeOnDelete(); // pvp
            $table->foreignId('trainer_id')->nullable()->constrained()->nullOnDelete();       // dresseur PNJ
            $table->foreignId('winner_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->enum('result', ['win', 'loss', 'fled', 'caught', 'forfeit', 'draw'])->nullable(); // du point de vue du joueur 1
            $table->unsignedSmallInteger('turn')->default(0);
            $table->unsignedInteger('seed');                         // graine aléatoire : combats rejouables / vérifiables
            $table->json('state');                                   // état courant complet
            $table->json('log')->nullable();                         // "Pikachu utilise Tonnerre !"...
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['character_id', 'status']);
        });

        // Actions choisies par chaque joueur pour un tour (indispensable en PvP :
        // le tour se résout quand les deux joueurs ont choisi).
        Schema::create('battle_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('battle_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('turn');
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->enum('action', ['move', 'switch', 'item', 'run', 'forfeit']);
            $table->json('payload')->nullable();                     // {"move_slot":2} / {"party_slot":3} / {"item_id":17}
            $table->timestamps();
            $table->unique(['battle_id', 'turn', 'character_id']);
        });

        // Défis PvP (invitation avant que le combat ne commence)
        Schema::create('battle_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenger_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('challenged_id')->constrained('characters')->cascadeOnDelete();
            $table->enum('status', ['pending', 'accepted', 'declined', 'expired'])->default('pending');
            $table->unsignedTinyInteger('level_cap')->nullable();    // ex: tous les Pokémon ramenés niv. 50
            $table->foreignId('battle_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_challenges');
        Schema::dropIfExists('battle_actions');
        Schema::dropIfExists('battles');
        Schema::dropIfExists('trade_items');
        Schema::dropIfExists('trade_pokemon');
        Schema::dropIfExists('trades');
    }
};
