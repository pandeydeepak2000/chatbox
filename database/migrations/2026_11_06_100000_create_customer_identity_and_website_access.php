<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Stage 1–2:
 *  - customer Support PIN identity (accounts, sessions, OTPs)       — Phase 2 §3, audit H8
 *  - website slugs for per-website admin URLs + identity mode        — Phase 2 §2.3, §3.2
 *  - website-level staff roles and company owner                     — Phase 2 §2.1
 *
 * Additive. Existing rows only receive values in NEW columns (slug, owner, identity_mode).
 */
return new class extends Migration
{
    private const RESERVED_SLUGS = ['admin', 'api', 'app', 'widget', 'login', 'register', 'platform', 'dashboard', 'workspace', 'demo', 'up', 'storage', 'uploads', 'public', 'assets', 'static', '_next'];

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->uuid('owner_user_id')->nullable()->after('status');
            $table->string('timezone', 64)->default('Asia/Kolkata')->after('owner_user_id');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->string('slug', 60)->nullable()->unique()->after('site_name');
            // guest_only | pin_optional | pin_required
            $table->string('identity_mode', 20)->default('pin_optional')->after('allowed_domains');
        });

        Schema::table('property_user', function (Blueprint $table) {
            // agent = work conversations/tickets/leads; admin = also manage this website's settings
            $table->string('role', 20)->default('agent')->after('user_id');
        });

        Schema::create('customer_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('email');
            $table->string('email_normalized');
            $table->string('pin_hash');
            $table->timestamp('email_verified_at')->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->unsignedTinyInteger('lockout_level')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->unsignedInteger('session_version')->default(1);
            $table->timestamp('pin_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'email_normalized']);
            $table->index('tenant_id');
        });

        Schema::create('customer_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignUuid('property_id')->constrained('properties')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->unsignedInteger('session_version');
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->index(['customer_account_id', 'revoked_at']);
        });

        Schema::create('customer_otps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->string('purpose', 20); // pin_reset | email_verify
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['customer_account_id', 'purpose', 'consumed_at']);
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->foreignUuid('customer_account_id')->nullable()->after('property_id')->constrained('customer_accounts')->nullOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignUuid('customer_account_id')->nullable()->after('visitor_id')->constrained('customer_accounts')->nullOnDelete();
            // true = created before the account's e-mail ownership was proven (possible dispute, §3.6)
            $table->boolean('pre_verification')->default(false)->after('customer_account_id');
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $used = [];
        foreach (DB::table('properties')->orderBy('created_at')->get(['id', 'site_name']) as $p) {
            $base = Str::slug($p->site_name) ?: 'website';
            $base = substr($base, 0, 40);
            if (strlen($base) < 3 || in_array($base, self::RESERVED_SLUGS, true)) {
                $base = 'site-' . $base;
            }
            $slug = $base;
            $i = 2;
            while (isset($used[$slug])) {
                $slug = $base . '-' . $i++;
            }
            $used[$slug] = true;
            // Existing websites keep today's behaviour (guest chat) until an admin changes it.
            DB::table('properties')->where('id', $p->id)->update(['slug' => $slug, 'identity_mode' => 'guest_only']);
        }

        foreach (DB::table('tenants')->get(['id']) as $t) {
            $owner = DB::table('users')->where('tenant_id', $t->id)->where('role', 'admin')->orderBy('created_at')->value('id');
            if ($owner) {
                DB::table('tenants')->where('id', $t->id)->update(['owner_user_id' => $owner]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_account_id');
            $table->dropColumn('pre_verification');
        });
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_account_id');
        });
        Schema::dropIfExists('customer_otps');
        Schema::dropIfExists('customer_sessions');
        Schema::dropIfExists('customer_accounts');
        Schema::table('property_user', function (Blueprint $table) {
            $table->dropColumn('role');
        });
        Schema::table('properties', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'identity_mode']);
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['owner_user_id', 'timezone']);
        });
    }
};
