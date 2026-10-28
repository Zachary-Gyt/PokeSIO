<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les GROUPES de joueurs (4 au plus, config/chat.php) et leurs invitations.
 * Un personnage est dans un groupe au plus (characters.group_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leader_id')->constrained('characters')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('user_id')->constrained('groups')->nullOnDelete();
            $table->timestamp('group_joined_at')->nullable()->after('group_id');
        });

        Schema::create('group_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inviter_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('characters')->cascadeOnDelete();
            $table->enum('status', ['pending', 'accepted', 'declined', 'expired'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_invitations');
        Schema::table('characters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn('group_joined_at');
        });
        Schema::dropIfExists('groups');
    }
};
