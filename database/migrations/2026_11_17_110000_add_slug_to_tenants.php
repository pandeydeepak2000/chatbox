<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Additive. A company gets its own dashboard address: https://host/{slug} (and /{slug}/inbox, /{slug}/tickets ...).
 * Every existing company is backfilled with a slug derived from its name; nothing else is touched, and /app and the
 * website sign-in addresses (/{website}/admin) keep working exactly as before. The owner can change the slug later.
 */
return new class extends Migration
{
    private const RESERVED = [
        'admin', 'api', 'app', 'widget', 'login', 'register', 'platform', 'dashboard', 'workspace', 'demo', 'up', 'storage', 'uploads', 'public',
        'assets', 'static', '_next', 'widget-js', 'favicon-ico', 'robots-txt', 'legacy-dashboard',
        'overview', 'inbox', 'tickets', 'leads', 'customers', 'knowledge', 'ai', 'aireview', 'websites', 'team', 'notifications', 'analytics', 'company',
    ];

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('slug', 40)->nullable()->after('name');
            $table->unique('slug');
        });

        $taken = [];
        foreach (DB::table('tenants')->orderBy('created_at')->get(['id', 'name']) as $t) {
            $base = substr(Str::slug((string) $t->name) ?: 'company', 0, 34);
            if (strlen($base) < 3) {
                $base = str_pad($base, 3, 'x');
            }
            $slug = $base;
            for ($i = 2; in_array($slug, self::RESERVED, true) || isset($taken[$slug]); $i++) {
                $slug = $base . '-' . $i;
            }
            $taken[$slug] = true;
            DB::table('tenants')->where('id', $t->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
