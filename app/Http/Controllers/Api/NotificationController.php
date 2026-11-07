<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\StaffNotification;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Services\Audit;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff notifications, follow-up tasks and Telegram channel administration.
 * Reading a notification never resolves a task; completing a task never resolves a ticket.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = StaffNotification::where('user_id', $user->id)->orderByDesc('created_at')->limit(50)->get();

        return response()->json([
            'success' => true,
            'unread' => StaffNotification::where('user_id', $user->id)->whereNull('read_at')->count(),
            'data' => $items,
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        StaffNotification::where('user_id', $request->user()->id)->where('id', $id)->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        StaffNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * My tasks + the team board. Team members see other people's tasks only as minimal
     * read-only summaries (no customer data or message content).
     */
    public function tasks(Request $request): JsonResponse
    {
        $user = $request->user();
        $restricted = $user->restrictedPropertyIds();
        $base = Task::where('tenant_id', $user->tenant_id)
            ->when($restricted !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('property_id', $restricted)->orWhere('owner_id', $user->id)));

        $mine = (clone $base)->where('owner_id', $user->id)->with('reminders')
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->orderBy('due_at')->limit(100)->get()
            ->map(fn (Task $t) => [
                'id' => $t->id, 'type' => $t->type, 'type_label' => Task::TYPES[$t->type] ?? $t->type, 'title' => $t->title,
                'status' => $t->status, 'due_at' => $t->due_at?->toIso8601String(), 'conversation_id' => $t->conversation_id, 'ticket_id' => $t->ticket_id,
                'reminders' => $t->reminders->map(fn (TaskReminder $r) => ['remind_at' => $r->remind_at?->toIso8601String(), 'sent' => (bool) $r->sent_at, 'cancelled' => (bool) $r->cancelled_at])->values(),
            ]);

        $team = (clone $base)->where('status', 'pending')->where(fn ($q) => $q->whereNull('owner_id')->orWhere('owner_id', '!=', $user->id))
            ->with('owner:id,name')->orderBy('due_at')->limit(100)->get()
            ->map(fn (Task $t) => ['id' => $t->id, 'type_label' => Task::TYPES[$t->type] ?? $t->type, 'owner' => $t->owner?->name, 'due_at' => $t->due_at?->toIso8601String(), 'status' => $t->status]);

        return response()->json(['success' => true, 'data' => ['mine' => $mine, 'team' => $team]]);
    }

    public function completeTask(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $task = Task::where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail();
        if ($task->owner_id !== $user->id && ! $user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Only the task owner or an admin can complete this task.'], 403);
        }
        $task->forceFill(['status' => 'done', 'completed_at' => now(), 'completed_by' => $user->id])->save();
        TaskReminder::where('task_id', $task->id)->whereNull('sent_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
        Audit::record('task.completed', $task);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------- Telegram (company admins)

    public function channels(Request $request): JsonResponse
    {
        $tenantId = $this->adminTenant($request);

        return response()->json([
            'success' => true,
            'data' => NotificationChannel::where('tenant_id', $tenantId)->orderBy('created_at')->get()->map->toSafeArray()->values(),
            'events' => collect(NotificationChannel::EVENTS)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
            'deliveries' => NotificationDelivery::where('tenant_id', $tenantId)->orderByDesc('id')->limit(50)
                ->get(['id', 'channel_id', 'event', 'status', 'attempts', 'last_error', 'sent_at', 'created_at']),
        ]);
    }

    public function saveChannel(Request $request, ?string $id = null): JsonResponse
    {
        $tenantId = $this->adminTenant($request);
        $channel = $id ? NotificationChannel::where('tenant_id', $tenantId)->where('id', $id)->firstOrFail() : null;
        $v = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'bot_token' => [$channel ? 'nullable' : 'required', 'string', 'regex:/^\d{5,15}:[A-Za-z0-9_-]{30,64}$/'],
            'chat_id' => ['required', 'string', 'max:64', 'regex:/^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(NotificationChannel::EVENTS))],
            'is_enabled' => ['sometimes', 'boolean'],
        ], ['bot_token.regex' => 'This does not look like a Telegram bot token (format 123456:ABC…).', 'chat_id.regex' => 'Use a numeric chat id (e.g. -1001234567890) or @channelname.']);

        $data = ['name' => $v['name'], 'chat_id' => $v['chat_id'], 'events' => array_values(array_unique($v['events'])), 'is_enabled' => $v['is_enabled'] ?? true];
        if (! empty($v['bot_token'])) {
            $data['bot_token'] = $v['bot_token'];
        }
        $channel = $channel ? tap($channel)->update($data) : NotificationChannel::create($data + ['tenant_id' => $tenantId, 'type' => 'telegram']);
        Audit::record($id ? 'telegram.updated' : 'telegram.created', $channel, ['events' => $data['events']]);

        return response()->json(['success' => true, 'data' => $channel->toSafeArray()], $id ? 200 : 201);
    }

    public function deleteChannel(Request $request, string $id): JsonResponse
    {
        $channel = NotificationChannel::where('tenant_id', $this->adminTenant($request))->where('id', $id)->firstOrFail();
        Audit::record('telegram.deleted', $channel);
        $channel->delete();

        return response()->json(['success' => true]);
    }

    /** Sends a real test message and reports the true result. */
    public function testChannel(Request $request, string $id): JsonResponse
    {
        $channel = NotificationChannel::where('tenant_id', $this->adminTenant($request))->where('id', $id)->firstOrFail();
        $result = TelegramService::send($channel, '✅ ChatBox test message — Telegram notifications are working for ' . ($request->user()->tenant?->name ?? 'your company') . '.');
        $channel->forceFill($result['ok'] ? ['last_success_at' => now(), 'last_error' => null] : ['last_error' => $result['error']])->save();

        return response()->json(['success' => $result['ok'], 'message' => $result['ok'] ? 'Test message delivered.' : 'Telegram did not accept the message: ' . $result['error']], $result['ok'] ? 200 : 422);
    }

    private function adminTenant(Request $request): string
    {
        $user = $request->user();
        abort_unless($user->tenant_id && ($user->isAdmin()), 403, 'Only company administrators can manage notification channels.');

        return $user->tenant_id;
    }
}
