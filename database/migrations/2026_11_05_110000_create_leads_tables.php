<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Leads Management (docs/PHASE2_ARCHITECTURE.md §16).
 *
 * Until now the CRM treated every `visitors` row as a lead. A dedicated `leads` table is
 * added; existing CRM data is COPIED into it (visitors are not modified or deleted):
 *  - visitors that left a name, e-mail, phone, inquiry or a CRM status become leads
 *  - old stages map to the new ones (qualified -> interested, lost -> closed)
 *  - old CRM notes become an internal lead note
 *  - the visitor's conversations are linked to the lead
 */
return new class extends Migration
{
    private const STATUS_MAP = [
        'new' => 'new', 'contacted' => 'contacted', 'qualified' => 'interested',
        'interested' => 'interested', 'follow_up' => 'follow_up', 'converted' => 'converted',
        'lost' => 'closed', 'closed' => 'closed',
    ];

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('lead_counter')->default(0)->after('status');
        });

        // Website-level access for staff. No rows for a user = access to all company websites.
        Schema::create('property_user', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['property_id', 'user_id']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('property_id')->constrained('properties')->cascadeOnDelete();
            $table->unsignedInteger('lead_number');
            $table->foreignUuid('visitor_id')->nullable()->unique()->constrained('visitors')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website')->nullable();
            $table->string('interested_service', 60)->nullable();
            $table->text('inquiry_summary')->nullable();
            $table->string('status', 20)->default('new');
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('chat');
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'lead_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'last_interaction_at']);
            $table->index(['property_id', 'status']);
            $table->index('email_normalized');
            $table->index('assigned_to');
        });

        Schema::create('lead_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('lead_conversation', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['lead_id', 'conversation_id']);
            $table->index('conversation_id');
        });

        $this->backfillFromVisitors();
    }

    private function backfillFromVisitors(): void
    {
        $counters = [];

        DB::table('visitors')
            ->join('properties', 'properties.id', '=', 'visitors.property_id')
            ->select('visitors.*', 'properties.tenant_id')
            ->orderBy('visitors.created_at')
            ->orderBy('visitors.id')
            ->chunk(500, function ($visitors) use (&$counters) {
                foreach ($visitors as $v) {
                    $meta = json_decode((string) $v->metadata, true) ?: [];
                    $inquiry = $meta['initial_inquiry'] ?? null;
                    $hasData = $v->name || $v->email || $v->phone || $inquiry || isset($meta['lead_status']);
                    if (! $hasData) {
                        continue; // anonymous page view, never a lead in the old CRM either
                    }

                    $tenantId = $v->tenant_id;
                    $counters[$tenantId] = ($counters[$tenantId] ?? 0) + 1;
                    $leadId = (string) Str::uuid();
                    $lastMessageAt = DB::table('conversations')->where('visitor_id', $v->id)->max('last_message_at');
                    $now = now();

                    DB::table('leads')->insert([
                        'id' => $leadId,
                        'tenant_id' => $tenantId,
                        'property_id' => $v->property_id,
                        'lead_number' => $counters[$tenantId],
                        'visitor_id' => $v->id,
                        'name' => $v->name,
                        'email' => $v->email,
                        'email_normalized' => $v->email ? mb_strtolower(trim($v->email)) : null,
                        'phone' => $v->phone ? mb_substr($v->phone, 0, 50) : null,
                        'inquiry_summary' => $inquiry ? html_entity_decode($inquiry, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
                        'status' => self::STATUS_MAP[strtolower((string) ($meta['lead_status'] ?? 'new'))] ?? 'new',
                        'source' => 'chat',
                        'last_interaction_at' => $lastMessageAt ?? $v->last_seen_at ?? $v->created_at,
                        'created_at' => $v->created_at ?? $now,
                        'updated_at' => $now,
                    ]);

                    if (! empty($meta['lead_notes'])) {
                        DB::table('lead_notes')->insert([
                            'id' => (string) Str::uuid(),
                            'tenant_id' => $tenantId,
                            'lead_id' => $leadId,
                            'user_id' => null,
                            'body' => "Imported from the previous CRM notes:\n" . $meta['lead_notes'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    $links = DB::table('conversations')->where('visitor_id', $v->id)->pluck('id')
                        ->map(fn ($cid) => ['lead_id' => $leadId, 'conversation_id' => $cid, 'created_at' => $now])->all();
                    if ($links) {
                        DB::table('lead_conversation')->insert($links);
                    }
                }
            });

        foreach ($counters as $tenantId => $count) {
            DB::table('tenants')->where('id', $tenantId)->update(['lead_counter' => $count]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_conversation');
        Schema::dropIfExists('lead_notes');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('property_user');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('lead_counter');
        });
    }
};
