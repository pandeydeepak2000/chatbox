<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable()->after('status');
            $table->text('rating_feedback')->nullable()->after('rating');
            $table->timestamp('transcript_emailed_at')->nullable()->after('closed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['rating', 'rating_feedback', 'transcript_emailed_at']);
        });
    }
};
