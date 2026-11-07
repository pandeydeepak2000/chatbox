<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit B4: polling sync compared created_at (1-second precision) and lost messages
 * written in the same second. Every message now gets a per-conversation sequence
 * number assigned under a row lock; sync uses "seq > N".
 *
 * Additive only: existing message content is not changed. Existing messages receive
 * a seq in their current display order (created_at, id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedInteger('last_seq')->default(0)->after('status');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedInteger('seq')->nullable()->after('conversation_id');
        });

        DB::table('conversations')->select('id')->orderBy('id')->chunk(200, function ($conversations) {
            foreach ($conversations as $conversation) {
                $seq = 0;
                $ids = DB::table('messages')
                    ->where('conversation_id', $conversation->id)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->pluck('id');

                foreach ($ids as $id) {
                    DB::table('messages')->where('id', $id)->update(['seq' => ++$seq]);
                }

                DB::table('conversations')->where('id', $conversation->id)->update(['last_seq' => $seq]);
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unique(['conversation_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['conversation_id', 'seq']);
            $table->dropColumn('seq');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('last_seq');
        });
    }
};
