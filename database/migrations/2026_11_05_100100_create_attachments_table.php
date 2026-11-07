<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit C4/H9: uploads are stored on the private disk (storage/app/private), never in
 * public/, and served only through signed, expiring download links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained('properties')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->string('uploader_type', 20); // visitor | user
            $table->uuid('uploader_id');
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('original_name', 200);
            $table->string('mime', 120);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['uploader_type', 'uploader_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
