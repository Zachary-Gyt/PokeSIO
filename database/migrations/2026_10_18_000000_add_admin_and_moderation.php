<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 10 : les comptes administrateurs (maîtres du jeu) et la modération.
 *
 *   users.is_admin        le compte a accès aux commandes /… du chat et à la page /admin
 *   users.banned_at       date du bannissement (null = pas banni)
 *   users.banned_until    fin du bannissement (null + banned_at rempli = définitif)
 *   users.ban_reason      motif affiché au joueur
 *   users.muted_until     le joueur ne peut plus écrire dans le chat jusqu'à cette date
 *
 *   admin_actions         le journal : qui a fait quoi, à qui, quand (rien ne se fait en silence)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->timestamp('banned_at')->nullable()->after('is_admin');
            $table->timestamp('banned_until')->nullable()->after('banned_at');
            $table->string('ban_reason', 255)->nullable()->after('banned_until');
            $table->timestamp('muted_until')->nullable()->after('ban_reason');
        });

        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            // nullOnDelete : le journal reste lisible même si un compte est supprimé
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('action', 30);          // kick, ban, unban, mute, give, money, givepoke, spawn, heal, tp...
            $table->string('details', 500)->nullable();
            $table->timestamps();
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'banned_at', 'banned_until', 'ban_reason', 'muted_until']);
        });
    }
};
