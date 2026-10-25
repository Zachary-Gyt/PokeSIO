<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La Banque : le compte de chaque personnage.
 *   bank_balance    : le solde (avec 4 décimales : les intérêts tombent en continu, même pour quelques secondes)
 *   bank_updated_at : le dernier calcul des intérêts (ils sont calculés à chaque visite, pour tout le temps écoulé)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->decimal('bank_balance', 16, 4)->default(0);
            $table->timestamp('bank_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['bank_balance', 'bank_updated_at']);
        });
    }
};
