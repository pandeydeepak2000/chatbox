<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Additive. Per-company AI providers (own encrypted key each) and their models. `ai_settings` keeps the ACTIVE choice
 * (provider / model / key / base_url) exactly as before, so the existing engine, budgets and tests keep working;
 * it only gains pointers to the provider/model rows plus the company's "services we support" scope.
 * Existing companies are backfilled from their current ai_settings row; nothing is deleted or changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('slug', 40);                          // openai | gemini | orvapi | anthropic | custom-xxxxxx
            $table->string('type', 20);                          // openai | gemini | orvapi | anthropic | custom
            $table->string('name', 80);
            $table->string('base_url', 255)->nullable();         // custom providers only (named providers use fixed endpoints)
            $table->string('models_path', 120)->nullable();      // custom: model discovery path, e.g. /models
            $table->text('api_key')->nullable();                 // encrypted by the model cast, never returned to a browser
            $table->unsignedTinyInteger('timeout_seconds')->default(25);
            $table->boolean('json_mode')->default(false);        // custom: endpoint accepts response_format
            $table->boolean('enabled')->default(true);
            $table->string('approval_status', 12)->default('approved');   // custom endpoints: pending -> approved | rejected
            $table->string('approval_note', 200)->nullable();
            $table->uuid('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->string('connection_status', 24)->default('untested');
            $table->string('connection_message', 200)->nullable();
            $table->dateTime('connection_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('provider_id')->index();
            $table->string('provider_key', 24);                  // value used in ai_settings.provider (custom => openai_compatible)
            $table->string('model_id', 150);
            $table->string('display_name', 120);
            $table->boolean('enabled')->default(true);
            $table->string('catalog_status', 12)->default('unknown');     // listed | unlisted | unknown
            $table->string('test_status', 20)->default('untested');       // untested | available | unavailable | auth_failed | rate_limited | error
            $table->string('test_message', 200)->nullable();
            $table->dateTime('tested_at')->nullable();
            $table->decimal('input_per_million', 12, 4)->nullable();      // confirmed by the company admin, USD per 1M tokens
            $table->decimal('output_per_million', 12, 4)->nullable();
            $table->timestamps();
            $table->unique(['provider_id', 'model_id']);
        });

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->uuid('provider_id')->nullable()->after('tenant_id');
            $table->uuid('active_model_id')->nullable()->after('provider_id');
            $table->text('service_scope')->nullable();           // "services we support" (one per line): the AI answers about these only
            $table->unsignedTinyInteger('timeout_seconds')->default(25);
            $table->boolean('json_mode')->default(false);
        });

        // Backfill from the current single-provider settings (copy only; ai_settings itself is not modified here).
        $map = ['anthropic' => ['anthropic', 'Anthropic (Claude)'], 'openai' => ['openai', 'OpenAI'], 'orvapi' => ['orvapi', 'OrvAPI'], 'openai_compatible' => ['custom', 'Custom provider']];
        foreach (DB::table('ai_settings')->get() as $s) {
            [$type, $name] = $map[$s->provider] ?? ['custom', 'Custom provider'];
            $slug = $type === 'custom' ? 'custom-' . Str::lower(Str::random(6)) : $type;
            $providerId = (string) Str::uuid();
            DB::table('ai_providers')->insert([
                'id' => $providerId, 'tenant_id' => $s->tenant_id, 'slug' => $slug, 'type' => $type, 'name' => $name,
                'base_url' => $type === 'custom' ? $s->base_url : null, 'models_path' => null,
                'api_key' => $s->api_key,                          // same ciphertext (same APP_KEY): the key stays usable, never decrypted here
                'timeout_seconds' => 25, 'json_mode' => false, 'enabled' => true,
                'approval_status' => 'approved',                   // grandfathered: this endpoint was already in use
                'connection_status' => 'untested', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $modelRowId = null;
            if (! empty($s->model)) {
                $modelRowId = (string) Str::uuid();
                DB::table('ai_models')->insert([
                    'id' => $modelRowId, 'tenant_id' => $s->tenant_id, 'provider_id' => $providerId, 'provider_key' => $s->provider,
                    'model_id' => $s->model, 'display_name' => $s->model, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('ai_settings')->where('tenant_id', $s->tenant_id)->update(['provider_id' => $providerId, 'active_model_id' => $modelRowId]);
        }
    }

    public function down(): void
    {
        Schema::table('ai_settings', fn (Blueprint $t) => $t->dropColumn(['provider_id', 'active_model_id', 'service_scope', 'timeout_seconds', 'json_mode']));
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
