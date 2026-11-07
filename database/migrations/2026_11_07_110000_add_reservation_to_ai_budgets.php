<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security fix (S12): AI spend is reserved atomically (row lock) before each paid call and released
 * when the real cost is recorded, so parallel requests cannot overshoot the monthly/daily caps.
 * Reservations older than 5 minutes are ignored (crashed requests never block the AI for long).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_budgets', function (Blueprint $table) {
            $table->decimal('reserved_usd', 12, 6)->default(0)->after('daily_cap_usd');
            $table->timestamp('reserved_until')->nullable()->after('reserved_usd');
        });
    }

    public function down(): void
    {
        Schema::table('ai_budgets', function (Blueprint $table) {
            $table->dropColumn(['reserved_usd', 'reserved_until']);
        });
    }
};
