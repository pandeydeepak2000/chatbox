<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 5: knowledge base (upload -> extract -> approve -> index -> version) and AI settings,
 * prices, budgets and usage ledger (Phase 2 §6–7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained('properties')->cascadeOnDelete(); // NULL = company-wide
            $table->string('title', 200);
            $table->string('source_type', 20); // manual | file | faq | transcript
            $table->string('status', 20)->default('draft'); // draft, processing, pending_approval, approved, rejected, archived, failed
            $table->uuid('current_version_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['property_id', 'status']);
        });

        Schema::create('knowledge_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('file_path')->nullable();
            $table->string('original_name', 200)->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->longText('extracted_text')->nullable();
            $table->string('status', 20)->default('processing'); // processing | ready | failed
            $table->string('error', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'version']);
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('property_id')->nullable();
            $table->foreignUuid('document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->foreignUuid('version_id')->constrained('knowledge_versions')->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            $table->text('content');
            $table->text('keywords'); // normalised search terms
            $table->index(['tenant_id', 'property_id']);
            $table->index('version_id');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE knowledge_chunks ADD FULLTEXT knowledge_chunks_fulltext (content, keywords)');
        }

        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('provider', 30)->default('anthropic'); // anthropic | openai_compatible
            $table->string('base_url', 255)->nullable();
            $table->string('model', 100)->default('claude-opus-5-5');
            $table->string('effort', 10)->default('low');
            $table->text('api_key')->nullable(); // encrypted cast
            $table->unsignedSmallInteger('max_output_tokens')->default(1500);
            $table->text('instructions')->nullable(); // approved company policies / tone
            $table->timestamps();
        });

        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('model', 100);
            $table->decimal('input_per_million', 10, 4);
            $table->decimal('output_per_million', 10, 4);
            $table->timestamps();
            $table->unique(['provider', 'model']);
        });

        Schema::create('ai_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->decimal('monthly_cap_usd', 10, 2)->default(25);
            $table->decimal('daily_cap_usd', 10, 2)->default(3);
            $table->unsignedSmallInteger('per_visitor_daily_messages')->default(40);
            $table->unsignedTinyInteger('alert_threshold_pct')->default(80);
            $table->string('alert_sent_month', 7)->nullable();
            $table->string('limit_sent_month', 7)->nullable();
            $table->string('limit_sent_day', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('property_id')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->string('provider', 30);
            $table->string('model', 100);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('outcome', 30); // answered, clarify, escalate, error, blocked_budget, blocked_rate, refused
            $table->string('error', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('ai_usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('period', 12); // d:2026-11-05 | m:2026-11
            $table->unsignedInteger('requests')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unique(['tenant_id', 'period']);
        });

        // Editable price seed (USD per 1M tokens). Anthropic first-party list prices as of 2026-10.
        // Other providers' prices must be entered by the platform admin (not guessed here).
        $now = now();
        DB::table('ai_model_prices')->insert([
            ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'input_per_million' => 4.00, 'output_per_million' => 20.00, 'created_at' => $now, 'updated_at' => $now],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'input_per_million' => 2.00, 'output_per_million' => 10.00, 'created_at' => $now, 'updated_at' => $now],
            ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'input_per_million' => 0.10, 'output_per_million' => 0.50, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_counters');
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('ai_budgets');
        Schema::dropIfExists('ai_model_prices');
        Schema::dropIfExists('ai_settings');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_versions');
        Schema::dropIfExists('knowledge_documents');
    }
};
