<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Audit M3: append-only log of security-relevant staff actions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('actor_type', 20); // user | visitor | system | guest
            $table->uuid('actor_id')->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
