<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Events\TypingEvent;
use App\Http\Controllers\Controller;
use App\Models\CannedResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Property;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentChatController extends Controller
{
    /**
     * List conversations with live filters (unassigned, mine, all, closed).
     */
    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = $request->query('filter', 'all'); // all, unassigned, mine, closed
        $propertyId = $request->query('property_id');
        $search = $request->query('search');

        $query = Conversation::with([
            'visitor',
            'department',
            'assignedAgent',
            'latestMessage',
        ])->withCount(['messages as unread_count' => function ($q) {
            $q->where('sender_type', 'visitor')->where('is_read', false);
        }]);

        // Tenant scope (super admin sees all, others see tenant properties)
        if (! $user->isSuperAdmin()) {
            $query->whereHas('property', function ($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id);
            });
        }

        if (! empty($propertyId)) {
            $query->where('property_id', $propertyId);
        }

        // Apply tab filters
        match ($filter) {
            'unassigned' => $query->where(function ($q) {
                $q->whereNull('assigned_agent_id')->orWhere('status', 'unassigned');
            })->where('status', '!=', 'closed'),

            'mine' => $query->where('assigned_agent_id', $user->id)
                ->where('status', '!=', 'closed'),

            'closed' => $query->where('status', 'closed'),

            default => $query->where('status', '!=', 'closed'),
        };

        // Search in visitor name/email or message content
        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('visitor', function ($vq) use ($search) {
                    $vq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('messages', function ($mq) use ($search) {
                    $mq->where('content', 'like', "%{$search}%");
                });
            });
        }

        $conversations = $query->orderByDesc('last_message_at')->paginate(25);

        return response()->json([
            'success' => true,
            'data' => $conversations,
        ]);
    }

    /**
     * Show single conversation with complete message history and visitor intel.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        $conversation->load([
            'visitor',
            'department',
            'assignedAgent',
            'messages.sender',
            'property',
        ]);

        return response()->json([
            'success' => true,
            'data' => $conversation,
        ]);
    }

    /**
     * Send an agent response in the conversation.
     */
    public function sendMessage(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'message_type' => ['nullable', 'string', 'in:text,image,file'],
            'attachment_url' => ['nullable', 'string', 'url'],
        ]);

        // Auto-assign to responding agent if unassigned
        if (empty($conversation->assigned_agent_id)) {
            $conversation->assigned_agent_id = $user->id;
            if ($conversation->status === 'unassigned') {
                $conversation->status = 'open';
            }
        }

        $conversation->last_message_at = now();
        $conversation->save();

        // Anti-XSS Sanitization
        $cleanContent = htmlspecialchars(trim(strip_tags($validated['content'])), ENT_QUOTES, 'UTF-8');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'agent',
            'sender_id' => $user->id,
            'message_type' => $validated['message_type'] ?? 'text',
            'content' => $cleanContent,
            'attachment_url' => $validated['attachment_url'] ?? null,
            'is_read' => true,
        ]);

        $message->load('sender');

        // Realtime Event: broadcast to visitor on private-chat.{conversationId}
        try {
            broadcast(new MessageSent($message))->toOthers();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('WebSocket broadcast skipped: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $message,
        ], 201);
    }

    /**
     * Assign conversation to an agent.
     */
    public function assign(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        $validated = $request->validate([
            'agent_id' => ['nullable', 'string', 'exists:users,id'],
        ]);

        $targetAgentId = $validated['agent_id'] ?? $user->id;

        $conversation->update([
            'assigned_agent_id' => $targetAgentId,
            'status' => $conversation->status === 'unassigned' ? 'open' : $conversation->status,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Conversation assigned.',
            'data' => $conversation->load('assignedAgent'),
        ]);
    }

    /**
     * Update conversation status (open, pending, closed).
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:unassigned,open,pending,closed'],
        ]);

        $status = $validated['status'];
        $closedAt = $status === 'closed' ? now() : null;

        $conversation->update([
            'status' => $status,
            'closed_at' => $closedAt,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Conversation status updated to ' . $status,
            'data' => $conversation,
        ]);
    }

    /**
     * Transfer conversation to another department or agent.
     */
    public function transfer(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        $validated = $request->validate([
            'department_id' => ['nullable', 'string', 'exists:departments,id'],
            'agent_id' => ['nullable', 'string', 'exists:users,id'],
        ]);

        $updates = [];
        if (array_key_exists('department_id', $validated)) {
            $updates['department_id'] = $validated['department_id'];
        }
        if (array_key_exists('agent_id', $validated)) {
            $updates['assigned_agent_id'] = $validated['agent_id'];
        }

        $conversation->update($updates);

        return response()->json([
            'success' => true,
            'message' => 'Conversation transferred.',
            'data' => $conversation->load(['department', 'assignedAgent']),
        ]);
    }

    /**
     * Mark all visitor messages in conversation as read.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        Message::where('conversation_id', $conversation->id)
            ->where('sender_type', 'visitor')
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Messages marked as read.',
        ]);
    }

    /**
     * Agent typing indicator trigger.
     */
    public function typing(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->findAuthorizedConversation($user, $id);

        $validated = $request->validate([
            'is_typing' => ['required', 'boolean'],
        ]);

        broadcast(new TypingEvent(
            $conversation->id,
            'agent',
            $user->name,
            $validated['is_typing']
        ))->toOthers();

        return response()->json(['success' => true]);
    }

    /**
     * Fetch canned responses for quick keyboard shortcuts (/greet, /pricing, etc.)
     */
    public function cannedResponses(Request $request): JsonResponse
    {
        $user = $request->user();
        $propertyId = $request->query('property_id');

        $query = CannedResponse::query();

        if (! empty($propertyId)) {
            $query->where('property_id', $propertyId);
        } elseif (! $user->isSuperAdmin()) {
            $query->whereHas('property', function ($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id);
            });
        }

        $responses = $query->orderBy('shortcut')->get();

        return response()->json([
            'success' => true,
            'data' => $responses,
        ]);
    }

    /**
     * Dashboard live metrics and KPIs.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $convQuery = Conversation::query();
        $visQuery = Visitor::query();
        $userQuery = User::where('role', '!=', 'super_admin');

        if (! $user->isSuperAdmin()) {
            $convQuery->whereHas('property', fn($q) => $q->where('tenant_id', $user->tenant_id));
            $visQuery->whereHas('property', fn($q) => $q->where('tenant_id', $user->tenant_id));
            $userQuery->where('tenant_id', $user->tenant_id);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_conversations' => (clone $convQuery)->count(),
                'open_conversations' => (clone $convQuery)->whereIn('status', ['unassigned', 'open', 'pending'])->count(),
                'unassigned_conversations' => (clone $convQuery)->where('status', 'unassigned')->count(),
                'closed_conversations' => (clone $convQuery)->where('status', 'closed')->count(),
                'total_visitors' => $visQuery->count(),
                'online_agents' => $userQuery->where('is_online', true)->count(),
            ],
        ]);
    }

    /**
     * Save a new canned response shortcut.
     */
    public function storeCannedResponse(Request $request, string $propertyId): JsonResponse
    {
        $user = $request->user();
        $property = Property::where('id', $propertyId);
        if (! $user->isSuperAdmin()) {
            $property->where('tenant_id', $user->tenant_id);
        }
        $prop = $property->firstOrFail();

        $validated = $request->validate([
            'shortcut' => ['required', 'string', 'max:50'],
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $shortcut = str_starts_with($validated['shortcut'], '/') ? $validated['shortcut'] : '/' . $validated['shortcut'];

        $canned = CannedResponse::updateOrCreate(
            ['property_id' => $prop->id, 'shortcut' => $shortcut],
            ['content' => $validated['content'], 'created_by' => $user->id]
        );

        return response()->json([
            'success' => true,
            'message' => 'Canned response saved.',
            'data' => $canned,
        ], 201);
    }

    /**
     * Delete canned response shortcut.
     */
    public function destroyCannedResponse(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $canned = CannedResponse::where('id', $id);
        if (! $user->isSuperAdmin()) {
            $canned->whereHas('property', fn($q) => $q->where('tenant_id', $user->tenant_id));
        }
        $canned = $canned->firstOrFail();
        $canned->delete();

        return response()->json([
            'success' => true,
            'message' => 'Canned response deleted.',
        ]);
    }

    /**
     * List CRM Leads (Visitors captured with inquiries/contacts).
     */
    public function leads(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = $request->query('search');
        $status = $request->query('status');
        $propertyId = $request->query('property_id');

        $query = Visitor::with(['property', 'conversations.messages' => function ($q) {
            $q->latest()->limit(1);
        }])->orderBy('created_at', 'desc');

        if (! $user->isSuperAdmin()) {
            $query->whereHas('property', function ($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id);
            });
        }

        if (! empty($propertyId)) {
            $query->where('property_id', $propertyId);
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $leads = $query->paginate($request->query('per_page', 50));

        $data = collect($leads->items())->map(function ($visitor) {
            $meta = $visitor->metadata ?? [];
            $latestConv = $visitor->conversations->first();
            $latestMsg = $latestConv?->messages?->first()?->content;

            return [
                'id' => $visitor->id,
                'name' => $visitor->name ?: 'Anonymous Visitor',
                'email' => $visitor->email,
                'phone' => $visitor->phone,
                'city' => $visitor->city,
                'country_code' => $visitor->country_code,
                'ip_address' => $visitor->ip_address,
                'browser' => $visitor->browser,
                'os' => $visitor->os,
                'current_page_url' => $visitor->current_page_url,
                'lead_status' => $meta['lead_status'] ?? 'new',
                'lead_notes' => $meta['lead_notes'] ?? '',
                'initial_inquiry' => $meta['initial_inquiry'] ?? $latestMsg ?? 'No inquiry recorded',
                'property_name' => $visitor->property?->site_name,
                'property_id' => $visitor->property_id,
                'conversation_id' => $latestConv?->id,
                'created_at' => $visitor->created_at?->toIso8601String(),
                'last_seen_at' => $visitor->last_seen_at?->toIso8601String(),
            ];
        });

        if (! empty($status)) {
            $data = $data->filter(fn($item) => $item['lead_status'] === $status)->values();
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total' => $leads->total(),
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
            ],
        ]);
    }

    /**
     * Update CRM Lead Status and Notes.
     */
    public function updateLead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $query = Visitor::where('id', $id);

        if (! $user->isSuperAdmin()) {
            $query->whereHas('property', function ($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id);
            });
        }

        $visitor = $query->firstOrFail();

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'lead_status' => ['nullable', 'string', 'in:new,contacted,qualified,converted,lost'],
            'lead_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['name'])) {
            $visitor->name = $validated['name'];
        }
        if (isset($validated['email'])) {
            $visitor->email = $validated['email'];
        }
        if (isset($validated['phone'])) {
            $visitor->phone = $validated['phone'];
        }

        $meta = $visitor->metadata ?? [];
        if (isset($validated['lead_status'])) {
            $meta['lead_status'] = $validated['lead_status'];
        }
        if (isset($validated['lead_notes'])) {
            $meta['lead_notes'] = $validated['lead_notes'];
        }

        $visitor->metadata = $meta;
        $visitor->save();

        return response()->json([
            'success' => true,
            'message' => 'Lead updated successfully.',
            'data' => $visitor,
        ]);
    }

    /**
     * Delete Lead.
     */
    public function deleteLead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $query = Visitor::where('id', $id);

        if (! $user->isSuperAdmin()) {
            $query->whereHas('property', function ($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id);
            });
        }

        $visitor = $query->firstOrFail();
        $visitor->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lead deleted successfully.',
        ]);
    }

    /**
     * Toggle Block/Unblock Spammer Visitor (Security Hardening).
     */
    public function toggleBlockVisitor(Request $request, string $visitorId): JsonResponse
    {
        $user = $request->user();
        $query = Visitor::where('id', $visitorId);
        if (! $user->isSuperAdmin()) {
            $query->whereHas('property', fn($q) => $q->where('tenant_id', $user->tenant_id));
        }
        $visitor = $query->firstOrFail();
        $visitor->is_blocked = ! $visitor->is_blocked;
        $visitor->save();

        return response()->json([
            'success' => true,
            'message' => $visitor->is_blocked ? 'Visitor blocked successfully.' : 'Visitor unblocked.',
            'is_blocked' => $visitor->is_blocked,
        ]);
    }

    /**
     * Find conversation and verify tenant authorization.
     */
    private function findAuthorizedConversation(User $user, string $id): Conversation
    {
        $query = Conversation::where('id', $id);

        if (! $user->isSuperAdmin()) {
            $query->whereHas('property', function ($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id);
            });
        }

        return $query->firstOrFail();
    }
}
