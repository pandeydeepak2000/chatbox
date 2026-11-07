<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Stage 6: SaaS foundation — plans with limits, one subscription per company.
 * No payment gateway yet: `external_ref` / `provider` are reserved for it. Existing companies get
 * a subscription matching their current `tenants.plan` value (nothing else changes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            // null limit = unlimited
            $table->json('limits');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('status', 20)->default('active'); // trialing, active, past_due, cancelled
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->string('provider', 30)->nullable();      // future: razorpay, stripe ...
            $table->string('external_ref', 100)->nullable(); // future: gateway subscription id
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        $now = now();
        $plans = [
            ['code' => 'free', 'name' => 'Free', 'price_monthly' => 0, 'limits' => ['websites' => 1, 'staff' => 2, 'conversations_per_month' => 300, 'knowledge_documents' => 10, 'ai_monthly_usd' => 2], 'sort' => 1, 'is_public' => true],
            ['code' => 'pro', 'name' => 'Pro', 'price_monthly' => 1999, 'limits' => ['websites' => 3, 'staff' => 10, 'conversations_per_month' => 5000, 'knowledge_documents' => 200, 'ai_monthly_usd' => 25], 'sort' => 2, 'is_public' => true],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'price_monthly' => 7999, 'limits' => ['websites' => 20, 'staff' => 50, 'conversations_per_month' => null, 'knowledge_documents' => 2000, 'ai_monthly_usd' => 150], 'sort' => 3, 'is_public' => true],
            ['code' => 'internal', 'name' => 'Internal (own websites)', 'price_monthly' => 0, 'limits' => ['websites' => null, 'staff' => null, 'conversations_per_month' => null, 'knowledge_documents' => null, 'ai_monthly_usd' => null], 'sort' => 9, 'is_public' => false],
        ];
        foreach ($plans as $p) {
            DB::table('plans')->insert(array_merge($p, ['limits' => json_encode($p['limits']), 'currency' => 'INR', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]));
        }

        $planIds = DB::table('plans')->pluck('id', 'code');
        foreach (DB::table('tenants')->get(['id', 'plan']) as $t) {
            DB::table('subscriptions')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $t->id,
                'plan_id' => $planIds[$t->plan] ?? $planIds['free'],
                'status' => 'active',
                'current_period_start' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
