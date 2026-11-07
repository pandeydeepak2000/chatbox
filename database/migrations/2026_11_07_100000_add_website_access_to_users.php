<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security fix (F1): website access is now stored explicitly instead of being inferred from an
 * empty property_user list. "selected" with no websites left means NO access (fails closed);
 * previously, removing an agent's last website silently gave them every company website.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('website_access', 10)->default('all')->after('role');
        });

        // Agents that are currently limited to specific websites keep exactly that limit.
        DB::table('users')
            ->where('role', 'agent')
            ->whereIn('id', DB::table('property_user')->select('user_id'))
            ->update(['website_access' => 'selected']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('website_access');
        });
    }
};
