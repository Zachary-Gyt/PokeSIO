<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 8 : dresseurs complets, arènes et "Tenant du titre".
 *
 *   trainers.items      objets que le dresseur peut utiliser en combat : ["super-potion", "super-potion"]
 *   trainers.level_cap  niveau maximum autorisé pour le défier (tenant du titre) ; null = pas de limite
 *   trainers.is_title   ce dresseur est un "Tenant du titre" : son équipe est celle du dernier joueur qui l'a battu
 *
 *   title_holders       l'historique des tenants : le tenant ACTUEL est la ligne sans lost_at
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainers', function (Blueprint $table) {
            $table->json('items')->nullable()->after('ai_level');
            $table->unsignedTinyInteger('level_cap')->nullable()->after('items');
            $table->boolean('is_title')->default(false)->after('level_cap');
        });

        Schema::create('title_holders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trainer_id')->constrained()->cascadeOnDelete();
            // Le joueur qui détient le titre (null : le dresseur d'origine, ou un personnage supprimé depuis)
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 20);
            $table->enum('gender', ['male', 'female'])->nullable();
            // "Photo" de l'équipe au moment de la victoire : espèce, niveau, nature, talent, IV, EV, attaques...
            $table->json('team');
            $table->unsignedInteger('defenses')->default(0);        // défis repoussés
            $table->timestamp('won_at')->useCurrent();
            $table->timestamp('lost_at')->nullable();               // null = tenant actuel
            $table->foreignId('lost_to_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->timestamps();
            $table->index(['trainer_id', 'lost_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_holders');
        Schema::table('trainers', function (Blueprint $table) {
            $table->dropColumn(['items', 'level_cap', 'is_title']);
        });
    }
};
