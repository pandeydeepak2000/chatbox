<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiResponder;
use App\Services\Notifier;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| ChatBox background work (cPanel: ONE cron line runs everything)
|--------------------------------------------------------------------------
| * * * * * cd /home/USER/chatbox && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
*/

Artisan::command('chatbox:reminders', function () {
    $sent = Notifier::sendDueReminders();
    $sla = Notifier::checkSalesFirstResponse();
    $retried = Notifier::retryFailedDeliveries();
    $ticketReminders = Notifier::checkPendingTicketReminders();
    Cache::put('chatbox:scheduler_heartbeat', now()->toIso8601String(), now()->addDay());
    $this->info("reminders={$sent} sales_alerts={$sla} ticket_reminders={$ticketReminders} telegram_retries={$retried}");
})->purpose('Send due task reminders, sales 30-working-minute alerts and retry failed Telegram deliveries');

Artisan::command('chatbox:daily-digest', function () {
    $this->info('digests=' . Notifier::dailyDigest());
})->purpose('10:00 IST digest of unresolved tickets and pending follow-up tasks');

Artisan::command('chatbox:process-ai', function () {
    // Fallback for AI replies whose after-response run did not finish (timeouts, crashes).
    $n = 0;
    Conversation::whereNotNull('ai_pending_since')->where('ai_pending_since', '<=', now()->subSeconds(90))
        ->limit(20)->get()->each(function (Conversation $c) use (&$n) {
            $last = Message::where('conversation_id', $c->id)->where('sender_type', 'visitor')->orderByDesc('seq')->first();
            if (! $last || $c->ai_pending_since->lt(now()->subMinutes(15))) {
                $c->forceFill(['ai_pending_since' => null])->save(); // too old: staff will see it unanswered

                return;
            }
            app(AiResponder::class)->run($c->id, $last->id);
            $n++;
        });
    $this->info("processed={$n}");
})->purpose('Retry AI replies that were not completed after the HTTP response');

Artisan::command('chatbox:env-check', function () {
    $rows = [];
    $ok = fn ($b) => $b ? 'OK' : 'MISSING';
    $rows[] = ['PHP >= 8.2', PHP_VERSION, $ok(version_compare(PHP_VERSION, '8.2.0', '>='))];
    foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'zip', 'xmlwriter', 'dom', 'ctype', 'curl', 'bcmath', 'intl'] as $ext) {
        $rows[] = ["ext-{$ext}", extension_loaded($ext) ? 'loaded' : 'not loaded', $ok(extension_loaded($ext)) . ($ext === 'intl' ? ' (optional)' : '')];
    }
    $rows[] = ['memory_limit', ini_get('memory_limit'), ''];
    $rows[] = ['max_execution_time', ini_get('max_execution_time'), ''];
    $rows[] = ['upload_max_filesize', ini_get('upload_max_filesize'), ''];
    $rows[] = ['post_max_size', ini_get('post_max_size'), ''];
    $rows[] = ['storage/ writable', storage_path(), $ok(is_writable(storage_path()))];
    $rows[] = ['bootstrap/cache writable', base_path('bootstrap/cache'), $ok(is_writable(base_path('bootstrap/cache')))];
    try {
        $version = DB::selectOne('select version() as v')->v ?? '?';
        $rows[] = ['Database', DB::getDriverName() . ' ' . $version, 'OK'];
    } catch (\Throwable $e) {
        $rows[] = ['Database', 'connection failed', 'MISSING'];
    }
    $beat = Cache::get('chatbox:scheduler_heartbeat');
    $rows[] = ['Cron (schedule:run) last ran', $beat ?? 'never', $beat ? 'OK' : 'CHECK CRON'];
    $rows[] = ['QUEUE_CONNECTION', config('queue.default'), config('queue.default') === 'database' ? 'OK' : 'expected database'];
    $rows[] = ['BROADCAST_CONNECTION', config('broadcasting.default'), in_array(config('broadcasting.default'), ['null', 'log'], true) ? 'OK (polling)' : 'needs a realtime server'];
    $this->table(['Check', 'Value', 'Status'], $rows);
})->purpose('Check that this cPanel server meets ChatBox requirements');

/*
| Security housekeeping (hourly):
|  - uploads that were never sent in a message are deleted after 24 h (no free file hosting,
|    no disk filling with orphan files);
|  - documents stuck in "processing" for over an hour (e.g. the parser hit a PHP fatal error on a
|    hostile file) are marked failed so they can be reviewed or replaced.
*/
Artisan::command('chatbox:maintenance', function () {
    $orphans = \App\Models\Attachment::whereNull('conversation_id')->where('created_at', '<', now()->subDay())->limit(500)->get();
    foreach ($orphans as $a) {
        \Illuminate\Support\Facades\Storage::disk($a->disk ?: 'local')->delete($a->path);
        $a->delete();
    }

    $stuck = \App\Models\KnowledgeVersion::where('status', 'processing')->where('updated_at', '<', now()->subHour())->get();
    foreach ($stuck as $v) {
        $v->forceFill(['status' => 'failed', 'error' => 'Processing did not finish. The file may be damaged or too complex — please upload it again or paste the text as an article.'])->save();
        $doc = $v->document;
        if ($doc && $doc->status === 'processing') {
            $doc->forceFill(['status' => 'failed'])->save();
        }
    }

    $this->info("orphan_attachments={$orphans->count()} stuck_documents={$stuck->count()}");
})->purpose('Delete unsent uploads and fail stuck document processing');
Schedule::command('chatbox:maintenance')->hourly()->withoutOverlapping(30);

// Every minute: reminders/SLA/Telegram retries, AI fallback, and a short-lived queue worker.
Schedule::command('chatbox:reminders')->everyMinute()->withoutOverlapping(5);
Schedule::command('chatbox:process-ai')->everyMinute()->withoutOverlapping(5);
Schedule::command('chatbox:ai-regression')->dailyAt('03:30')->timezone('Asia/Kolkata');
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=2 --timeout=45')->everyMinute()->withoutOverlapping(2);
Schedule::command('chatbox:daily-digest')->dailyAt('10:00')->timezone('Asia/Kolkata');
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
