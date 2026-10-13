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
        Schema::create('visitors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('visitor_token', 100);
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('os', 100)->nullable();
            $table->text('current_page_url')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'visitor_token']);
            $table->index(['property_id', 'last_seen_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
