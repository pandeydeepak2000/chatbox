<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit C3/H5: the webhook signing secret gets its own encrypted, hidden column.
 * Existing secrets stored inside widget_config keep working (read as a fallback)
 * and are moved into this column the next time an admin saves the website settings,
 * so this migration does not rewrite any existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable()->after('widget_config');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
};
