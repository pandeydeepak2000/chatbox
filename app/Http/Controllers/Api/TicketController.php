<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\Audit;
use App\Services\ConversationService;
use App\Services\Notifier;
use App\Services\TicketService;
use App\Support\SafeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff ticket desk. Every query goes through Ticket::visibleTo() (company + website scope,
 * plus previous/current assignees), so permissions are enforced on the server.
 */
class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'priority' => ['nullable', Rule::in(array_keys(Ticket::PRIORITIES))],
            'category' => ['nullable', Rule::in(array_keys(Ticket::CATEGORIES))],
            'property_id' => ['nullable', 'string', 'max:64'],
            'assigned_to' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = Ticket::visibleTo($user)->with(['property:id,site_name', 'assignee:id,name', 'customerAccount:id,email,email_verified_at', 'visitor:id,name,email']);
        if (! empty($v['status'])) {
            $v['status'] === 'unresolved' ? $q->whereIn('status', Ticket::UNRESOLVED) : $q->where('status', $v['status']);
        }
        foreach (['priority', 'category', 'property_id'] as $f) {
            if (! empty($v[$f])) {
                $q->where("tickets.{$f}", $v[$f]);
            }
        }
        if (! empty($v['assigned_to'])) {
            match ($v['assigned_to']) {
                'me' => $q->where('assigned_to', $user->id),
                'unassigned' => $q->whereNull('assigned_to'),
                default => $q->where('assigned_to', $v['assigned_to']),
            };
        }
        if (! empty($v['search'])) {
            $term = '%' . addcslashes($v['search'], '%_\\') . '%';
            $q->where(fn ($w) => $w->where('reference', 'like', $term)->orWhere('subject', 'like', $term));
        }

        // Pending work first, resolved items below (history kept).
        $q->orderByRaw("CASE WHEN status IN ('open','pending_team','pending_customer') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at');

        $page = $q->paginate($v['per_page'] ?? 25);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (Ticket $t) => $this->row($t))->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'counts' => [
                'unresolved' => Ticket::visibleTo($user)->whereIn('status', Ticket::UNRESOLVED)->count(),
                'mine' => Ticket::visibleTo($user)->whereIn('status', Ticket::UNRESOLVED)->where('assigned_to', $user->id)->count(),
                'unassigned' => Ticket::visibleTo($user)->whereIn('status', Ticket::UNRESOLVED)->whereNull('assigned_to')->count(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $ticket = $this->find($request->user(), $id);
        $ticket->load(['property:id,site_name', 'assignee:id,name', 'department:id,name', 'customerAccount', 'visitor', 'events', 'assignments.user:id,name']);
        $messages = $ticket->conversation_id
            ? Message::with('sender:id,name')->where('conversation_id', $ticket->conversation_id)->orderBy('seq')->get()
            : collect();

        return response()->json(['success' => true, 'data' => array_merge($this->row($ticket), [
            'summary' => $ticket->summary,
            'department' => $ticket->department?->only(['id', 'name']),
            'customer' => $ticket->customerAccount
                ? ['email' => $ticket->customerAccount->email, 'email_verified' => $ticket->customerAccount->isVerified()]
                : null,
            'visitor' => $ticket->visitor?->only(['id', 'name', 'email', 'phone']),
            'conversation' => $ticket->conversation ? $ticket->conversation->only(['id', 'status', 'handler', 'pre_verification']) : null,
            'messages' => $messages->map(fn (Message $m) => [
                'id' => $m->id,
                'seq' => $m->seq,
                'sender_type' => $m->sender_type,
                'sender_name' => $m->sender?->name,
                'visibility' => $m->visibility,
                'content' => $m->content,
                'attachment_url' => $m->attachment_url,
                'message_type' => $m->message_type,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
            'events' => $ticket->events->map(fn ($e) => ['type' => $e->type, 'data' => $e->data, 'actor_type' => $e->actor_type, 'actor_id' => $e->actor_id, 'created_at' => $e->created_at?->toIso8601String()])->values(),
            'assignments' => $ticket->assignments->map(fn ($a) => ['user' => $a->user?->name, 'assigned_at' => $a->assigned_at?->toIso8601String(), 'unassigned_at' => $a->unassigned_at?->toIso8601String(), 'current' => $a->unassigned_at === null])->values(),
        ])]);
    }

    /** Staff creates a ticket for a conversation they can access (idempotent). */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'conversation_id' => ['required', 'string'],
            'subject' => ['nullable', 'string', 'max:200'],
            'priority' => ['nullable', Rule::in(array_keys(Ticket::PRIORITIES))],
            'category' => ['nullable', Rule::in(array_keys(Ticket::CATEGORIES))],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);
        $conversation = $this->conversation($user, $v['conversation_id']);

        [$ticket, $created] = TicketService::createOrReuse($conversation, array_filter([
            'source' => 'staff',
            'subject' => isset($v['subject']) ? SafeText::field($v['subject'], 200) : null,
            'priority' => $v['priority'] ?? null,
            'category' => $v['category'] ?? null,
            'idempotency_key' => $v['idempotency_key'] ?? null,
            'actor' => $user,
        ]));
        Audit::record('ticket.created', $ticket, ['created' => $created]);

        return response()->json(['success' => true, 'created' => $created, 'data' => $this->row($ticket->fresh(['property', 'assignee']))], $created ? 201 : 200);
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $ticket = $this->find($user, $id);
        $v = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'attachment_id' => ['nullable', 'string', 'max:64'],
        ]);
        $content = SafeText::message($v['content']);
        if ($content === '' || ! $ticket->conversation) {
            return response()->json(['success' => false, 'message' => 'Message cannot be empty.'], 422);
        }
        $ticket->conversation->loadMissing('property');
        [$ref, $type] = AttachmentService::resolveForMessage($v['attachment_id'] ?? null, null, $ticket->conversation, 'user', $user->id);
        $message = TicketService::reply($ticket, $user, $content, $ref, $type);

        return response()->json(['success' => true, 'data' => $message], 201);
    }

    public function note(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $ticket = $this->find($user, $id);
        $v = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $body = SafeText::message($v['body']);
        if ($body === '') {
            return response()->json(['success' => false, 'message' => 'Note cannot be empty.'], 422);
        }

        return response()->json(['success' => true, 'data' => TicketService::note($ticket, $user, $body)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $ticket = $this->find($user, $id);
        $v = $request->validate([
            'status' => ['sometimes', Rule::in(array_keys(Ticket::STATUSES))],
            'priority' => ['sometimes', Rule::in(array_keys(Ticket::PRIORITIES))],
            'category' => ['sometimes', Rule::in(array_keys(Ticket::CATEGORIES))],
            'subject' => ['sometimes', 'string', 'max:200'],
            'department_id' => ['sometimes', 'nullable', 'string'],
            'assigned_to' => ['sometimes', 'nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        if (array_key_exists('department_id', $v) && $v['department_id'] !== null
            && ! Department::where('id', $v['department_id'])->where('property_id', $ticket->property_id)->exists()) {
            return response()->json(['success' => false, 'message' => 'The selected department is invalid.', 'errors' => ['department_id' => ['The selected department is invalid.']]], 422);
        }

        $changes = [];
        foreach (['priority', 'category', 'department_id'] as $f) {
            if (array_key_exists($f, $v) && $v[$f] !== $ticket->{$f}) {
                $changes[$f] = ['from' => $ticket->{$f}, 'to' => $v[$f]];
                $ticket->{$f} = $v[$f];
            }
        }
        if (isset($v['subject'])) {
            $ticket->subject = SafeText::field($v['subject'], 200) ?? $ticket->subject;
        }
        $ticket->save();
        foreach ($changes as $field => $change) {
            TicketService::event($ticket, $field . '_changed', $change, $user);
        }
        if (array_key_exists('assigned_to', $v)) {
            $assignee = $v['assigned_to'] ? User::where('id', $v['assigned_to'])->where('tenant_id', $ticket->tenant_id)->first() : null;
            if ($v['assigned_to'] && ! $assignee) {
                return response()->json(['success' => false, 'message' => 'The selected staff member is invalid.', 'errors' => ['assigned_to' => ['The selected staff member is invalid.']]], 422);
            }
            TicketService::assign($ticket, $assignee, $user);
        }
        if (isset($v['status'])) {
            TicketService::changeStatus($ticket, $v['status'], $user, $v['reason'] ?? null);
        }
        Audit::record('ticket.updated', $ticket, ['fields' => array_keys($v)]);

        return response()->json(['success' => true, 'data' => $this->row($ticket->fresh(['property', 'assignee']))]);
    }

    // ------------------------------------------------------------- conversation handling

    public function takeOver(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->conversation($user, $conversationId);
        ConversationService::takeOver($conversation, $user);

        return response()->json(['success' => true, 'data' => $conversation->fresh()->only(['id', 'handler', 'assigned_agent_id', 'status'])]);
    }

    public function resumeAi(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->conversation($user, $conversationId);
        if ($conversation->openTicket && ! $request->boolean('confirm_open_ticket')) {
            return response()->json(['success' => false, 'code' => 'open_ticket', 'message' => "This conversation has an open ticket ({$conversation->openTicket->reference}). Confirm to let the AI answer again anyway."], 409);
        }
        ConversationService::resumeAi($conversation, $user);

        return response()->json(['success' => true, 'data' => $conversation->fresh()->only(['id', 'handler'])]);
    }

    /** Internal note directly on a conversation (no ticket needed). Never visible in the widget. */
    public function conversationNote(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->conversation($user, $conversationId);
        $body = SafeText::message((string) $request->validate(['body' => ['required', 'string', 'max:5000']])['body']);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'ticket_id' => $conversation->openTicket?->id,
            'sender_type' => 'agent',
            'sender_id' => $user->id,
            'message_type' => 'text',
            'visibility' => 'internal',
            'content' => $body,
            'is_read' => true,
        ]);

        return response()->json(['success' => true, 'data' => $message->load('sender:id,name')], 201);
    }

    /** Mark an agent message as a sales quotation: reminders at +10 min, +30 min and +2 h. */
    public function markQuotation(Request $request, string $messageId): JsonResponse
    {
        $user = $request->user();
        $message = Message::findOrFail($messageId);
        $this->conversation($user, $message->conversation_id);
        if ($message->sender_type !== 'agent' || $message->visibility !== 'public') {
            return response()->json(['success' => false, 'message' => 'Only a staff reply sent to the customer can be marked as a quotation.'], 422);
        }
        if (! empty($message->metadata['quotation'])) {
            return response()->json(['success' => true, 'message' => 'Already marked as a quotation.']);
        }
        $task = Notifier::quotationSent($message, $user);

        return response()->json(['success' => true, 'data' => ['task_id' => $task->id, 'reminders' => $task->reminders()->pluck('remind_at')]], 201);
    }

    // ------------------------------------------------------------- helpers

    private function find(User $user, string $id): Ticket
    {
        return Ticket::visibleTo($user)->where('tickets.id', $id)->firstOrFail();
    }

    private function conversation(User $user, string $id): Conversation
    {
        $q = Conversation::where('id', $id)->with('property');
        if (! $user->isSuperAdmin()) {
            $q->whereHas('property', fn ($p) => $p->where('tenant_id', $user->tenant_id));
            if (($restricted = $user->restrictedPropertyIds()) !== null) {
                $q->whereIn('property_id', $restricted);
            }
        }

        return $q->firstOrFail();
    }

    private function row(Ticket $t): array
    {
        return [
            'id' => $t->id,
            'reference' => $t->reference,
            'subject' => $t->subject,
            'status' => $t->status,
            'status_label' => Ticket::STATUSES[$t->status] ?? $t->status,
            'priority' => $t->priority,
            'category' => $t->category,
            'source' => $t->source,
            'property' => $t->property ? ['id' => $t->property->id, 'site_name' => $t->property->site_name] : null,
            'assigned_to' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'customer_name' => $t->visitor?->name,
            'customer_email' => $t->customerAccount?->email ?? $t->visitor?->email,
            'conversation_id' => $t->conversation_id,
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
            'first_response_at' => $t->first_response_at?->toIso8601String(),
            'resolved_at' => $t->resolved_at?->toIso8601String(),
        ];
    }
}
