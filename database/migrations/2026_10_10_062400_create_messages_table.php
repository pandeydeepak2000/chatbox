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
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->enum('sender_type', ['visitor', 'agent', 'system', 'bot'])->default('visitor');
            $table->foreignUuid('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('message_type', ['text', 'image', 'file', 'action_button'])->default('text');
            $table->text('content');
            $table->string('attachment_url')->nullable();
            $table->boolean('is_read')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['conversation_id', 'is_read']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
