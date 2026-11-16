<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive: admin review of automated answers, human-approved corrections and retrieval regression cases. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_answer_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('property_id')->nullable()->index();
            $table->uuid('conversation_id')->index();
            $table->uuid('message_id');
            $table->uuid('reviewer_id');
            $table->unsignedTinyInteger('rating')->nullable();          // 1-5
            $table->boolean('is_incorrect')->default(false);
            $table->text('question')->nullable();                        // customer question the answer replied to
            $table->text('answer_excerpt')->nullable();                  // what the customer was told (for context)
            $table->text('correction_text')->nullable();                 // typed by the admin, never by a model
            $table->string('correction_status', 16)->default('none');    // none | pending | approved | rejected
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->uuid('knowledge_document_id')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'reviewer_id']);
            $table->index(['tenant_id', 'correction_status']);
        });

        Schema::create('ai_regression_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('property_id')->nullable();
            $table->uuid('review_id')->nullable();
            $table->text('question');
            $table->uuid('knowledge_document_id');                       // the approved document that must be found
            $table->string('last_result', 8)->nullable();                // pass | fail
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_regression_cases');
        Schema::dropIfExists('ai_answer_reviews');
    }
};
