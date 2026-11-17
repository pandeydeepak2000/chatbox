<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes ONE company's customer-side data (chats, tickets, leads, customers, notifications and logs) so it can
 * start fresh. The company itself, its staff, websites/widget, AI settings and keys, knowledge/FAQ, approved AI
 * corrections, Telegram settings, plan and the audit log are kept. Other companies are never touched.
 *
 *   php artisan chatbox:clear-chats salute          (asks you to type the address again to confirm)
 */
class ClearCompanyChatDataCommand extends Command
{
    protected $signature = 'chatbox:clear-chats {company : Company address (e.g. salute) or exact company name} {--force : Do not ask for confirmation}';

    protected $description = "Delete one company's chats, tickets, leads, customers and notifications (company, staff, websites and settings are kept)";

    public function handle(): int
    {
        $key = trim((string) $this->argument('company'));
        $matches = Tenant::where('slug', mb_strtolower($key))->get();
        if ($matches->isEmpty()) {
            $matches = Tenant::where('name', $key)->get();
        }
        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty() ? "No company found for \"{$key}\"." : "More than one company is named \"{$key}\". Use its address instead.");
            $this->line('Companies: ' . Tenant::orderBy('name')->get(['name', 'slug'])->map(fn ($t) => "{$t->name} ({$t->slug})")->implode(', '));

            return self::FAILURE;
        }
        $tenant = $matches->first();

        $plan = $this->plan($tenant->id);
        $this->info("Company: {$tenant->name} (address: {$tenant->slug})");
        $this->table(['What', 'Rows to delete'], collect($plan)->map(fn ($q, $label) => [$label, $q === null ? 0 : (clone $q)->count()])->values()->all());
        $this->line('Kept: company, staff, websites/widget, AI settings and keys, knowledge/FAQ, Telegram settings, plan, audit log.');

        if (! $this->option('force')) {
            $typed = $this->ask("This cannot be undone. Type the company address \"{$tenant->slug}\" to delete");
            if ($typed !== $tenant->slug) {
                $this->warn('Nothing deleted.');

                return self::FAILURE;
            }
        }

        $deleted = DB::transaction(function () use ($tenant, $plan) {
            DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->first();
            $done = [];
            foreach ($plan as $label => $q) {
                $done[$label] = $q === null ? 0 : (clone $q)->delete();
            }
            $reset = array_values(array_filter(['ticket_counter', 'lead_counter'], fn ($c) => Schema::hasColumn('tenants', $c)));
            if ($reset) {
                DB::table('tenants')->where('id', $tenant->id)->update(array_fill_keys($reset, 0));
            }

            return $done;
        });

        // Uploaded chat files of this company only.
        Storage::disk('local')->deleteDirectory('attachments/' . $tenant->id);

        Audit::record('company.chat_data_cleared', $tenant, ['rows' => array_sum($deleted)], tenantId: $tenant->id);
        $this->info('Done. ' . array_sum($deleted) . ' rows deleted for ' . $tenant->name . '. Ticket and lead numbers start again from 1.');

        return self::SUCCESS;
    }

    /**
     * Delete order: children before parents, so foreign keys hold on MySQL/MariaDB and SQLite.
     *
     * @return array<string, \Illuminate\Database\Query\Builder|null>
     */
    private function plan(string $tenantId): array
    {
        $props = DB::table('properties')->where('tenant_id', $tenantId)->select('id');
        $convs = DB::table('conversations')->whereIn('property_id', $props)->select('id');
        $tickets = DB::table('tickets')->where('tenant_id', $tenantId)->select('id');
        $tasks = DB::table('tasks')->where('tenant_id', $tenantId)->where(fn ($q) => $q->whereNotNull('conversation_id')->orWhereNotNull('ticket_id'));
        $accounts = DB::table('customer_accounts')->where('tenant_id', $tenantId)->select('id');
        $leads = DB::table('leads')->where('tenant_id', $tenantId)->select('id');
        $t = fn (string $table) => Schema::hasTable($table) ? DB::table($table) : null;   // tolerate an older schema

        return array_filter([
            'Task reminders (chat/ticket follow-ups)' => $t('task_reminders')?->whereIn('task_id', (clone $tasks)->select('id')),
            'Tasks (chat/ticket follow-ups)' => $tasks,
            'Ticket history' => $t('ticket_events')?->whereIn('ticket_id', $tickets),
            'Ticket assignments' => $t('ticket_assignments')?->whereIn('ticket_id', $tickets),
            'Ticket reminders' => $t('ticket_reminders')?->whereIn('ticket_id', $tickets),
            'Escalations' => $t('escalations')?->where('tenant_id', $tenantId),
            'Lead ↔ chat links' => $t('lead_conversation')?->whereIn('conversation_id', $convs),
            'Lead notes' => $t('lead_notes')?->whereIn('lead_id', $leads),
            'AI answer reviews' => $t('ai_answer_reviews')?->where('tenant_id', $tenantId),
            'AI usage log' => $t('ai_usage_logs')?->where('tenant_id', $tenantId),
            'Attachments' => $t('attachments')?->where('tenant_id', $tenantId),
            'Messages' => $t('messages')?->whereIn('conversation_id', $convs),
            'Chat state history' => $t('conversation_state_logs')?->whereIn('conversation_id', $convs),
            'Chat sessions' => $t('chat_sessions')?->where('tenant_id', $tenantId),
            'Tickets' => DB::table('tickets')->where('tenant_id', $tenantId),
            'Leads' => DB::table('leads')->where('tenant_id', $tenantId),
            'Chats' => DB::table('conversations')->whereIn('property_id', $props),
            'Customer OTPs' => $t('customer_otps')?->whereIn('customer_account_id', $accounts),
            'Customer sessions' => $t('customer_sessions')?->whereIn('customer_account_id', $accounts),
            'Customer accounts (PIN)' => $t('customer_accounts')?->where('tenant_id', $tenantId),
            'Visitors' => DB::table('visitors')->whereIn('property_id', $props),
            'Dashboard notifications' => $t('staff_notifications')?->where('tenant_id', $tenantId),
            'Telegram delivery history' => $t('notification_deliveries')?->where('tenant_id', $tenantId),
        ], fn ($q) => $q !== null);
    }
}
