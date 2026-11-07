<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit B2 follow-up. Messages written before this release were stored HTML-encoded
 * (e.g. "don&#039;t"). New messages are plain text and every screen now escapes on render,
 * so old rows would display their entities. Existing rows are only FLAGGED here; their
 * content is not rewritten. Message::getContentAttribute() decodes flagged rows on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('content_html_encoded')->default(false)->after('content');
        });

        DB::table('messages')->update(['content_html_encoded' => true]);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('content_html_encoded');
        });
    }
};
