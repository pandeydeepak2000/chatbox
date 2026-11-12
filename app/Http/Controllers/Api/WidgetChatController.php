<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationCreated;
use App\Events\MessageSent;
use App\Events\TypingEvent;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\CustomerAccount;
use App\Models\Department;
use App\Models\Message;
use App\Models\Property;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Ai\SupportBot;
use App\Services\AttachmentService;
use App\Services\BusinessHours;
use App\Services\ConversationService;
use App\Services\CustomerAuthService;
use App\Services\LeadCaptureService;
use App\Services\Notifier;
use App\Services\TicketService;
use App\Services\TranscriptService;
use App\Services\WebhookService;
use App\Support\SafeText;
use App\Support\WidgetAccess;
use App\Support\WidgetOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Pusher\Pusher;

/**
 * Public widget API. Every conversation-level endpoint goes through WidgetAccess (audit H8):
 * account-owned conversations need the customer's Support PIN session; guest conversations
 * need the visitor token of the device that created them.
 */
class WidgetChatController extends Controller
{
    public function init(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_key' => ['required', 'string', 'max:100'],
            'visitor_token' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'current_page_url' => ['nullable', 'string', 'max:2000'],
            'browser' => ['nullable', 'string', 'max:100'],
            'os' => ['nullable', 'string', 'max:100'],
        ]);

        $property = $this->activePropertyByKey($validated['property_key']);
        if (! $property) {
            return response()->json(['success' => false, 'message' => 'Invalid or inactive property key.'], 404);
        }
        if (! WidgetOrigin::allows($request, $property, required: true)) {
            return $this->forbiddenOrigin();
        }

        $visitorToken = $validated['visitor_token'] ?? null;
        if (! $visitorToken || ! preg_match('/^vtok_[A-Za-z0-9_]{6,64}$/', $visitorToken)) {
            $visitorToken = 'vtok_' . Str::random(40);
        }

        $visitor = Visitor::firstOrNew(['property_id' => $property->id, 'visitor_token' => $visitorToken]);
        if ($visitor->is_blocked) {
            return response()->json(['success' => false, 'message' => 'Access denied: This visitor has been blocked.'], 403);
        }

        $visitor->name = SafeText::field($validated['name'] ?? null) ?? $visitor->name;
        $visitor->email = isset($validated['email']) ? mb_strtolower(trim($validated['email'])) : $visitor->email;
        $visitor->phone = SafeText::field($validated['phone'] ?? null, 50) ?? $visitor->phone;
        $visitor->ip_address = $request->ip();
        $visitor->browser = SafeText::field($validated['browser'] ?? null, 100) ?? $visitor->browser;
        $visitor->os = SafeText::field($validated['os'] ?? null, 100) ?? $visitor->os;
        $visitor->current_page_url = SafeText::url($validated['current_page_url'] ?? null) ?? $visitor->current_page_url;
        $visitor->last_seen_at = now();
        $visitor->save();

        if (! empty($validated['name']) || ! empty($validated['email']) || ! empty($validated['phone'])) {
            LeadCaptureService::capture($visitor);
        }

        $customer = CustomerAuthService::resolve($request, $property);
        if ($customer) {
            CustomerAuthService::attachDevice($customer, $visitor);
        }

        // Active conversation: the customer's latest open one (any device) or this device's guest chat.
        $conversation = Conversation::where('property_id', $property->id)
            ->whereIn('status', ['unassigned', 'open', 'pending'])
            ->when($customer,
                fn ($q) => $q->where('customer_account_id', $customer->id),
                fn ($q) => $q->where('visitor_id', $visitor->id)->whereNull('customer_account_id'))
            ->latest('last_message_at')
            ->first();

        $departments = Cache::remember("prop_depts_{$property->id}", 120, fn () => $property->departments()->where('is_active', true)->get(['id', 'name', 'description']));
        $faqs = Cache::remember("prop_faqs_{$property->id}", 120, fn () => $property->faqs()->where('is_active', true)->get(['id', 'category', 'question', 'answer', 'order']));

        return response()->json([
            'success' => true,
            'data' => [
                'visitor' => [
                    'id' => $visitor->id,
                    'token' => $visitor->visitor_token,
                    'name' => $visitor->name,
                    'email' => $visitor->email,
                ],
                'property' => [
                    'id' => $property->id,
                    'site_name' => $property->site_name,
                    'identity_mode' => $property->identity_mode ?? 'guest_only',
                    'session_timeout_minutes' => (int) ($property->tenant?->settings['session_timeout_minutes'] ?? 30),
                    'widget_config' => $property->publicWidgetConfig(),
                ],
                'customer' => $customer ? ['id' => $customer->id, 'email' => $customer->email, 'email_verified' => $customer->isVerified()] : null,
                'departments' => $departments,
                'faqs' => $faqs,
                'conversation' => $conversation ? $this->conversationPayload($conversation) : null,
                'working_hours' => array_merge(BusinessHours::config($property), ['open_now' => BusinessHours::isOpen($property)]),
                // Optional WebSocket server (VPS only). On cPanel BROADCAST_CONNECTION=null and the widget polls.
                'broadcasting' => config('broadcasting.default') === 'reverb' ? [
                    'key' => config('reverb.apps.apps.0.key'),
                    'host' => config('reverb.apps.apps.0.options.host'),
                    'port' => (int) config('reverb.apps.apps.0.options.port'),
                    'scheme' => config('reverb.apps.apps.0.options.scheme'),
                ] : null,
            ],
        ]);
    }

    public function faqs(Request $request): JsonResponse
    {
        $property = $this->activePropertyByKey((string) $request->query('property_key'));
        abort_unless($property, 404);
        $faqs = Cache::remember("prop_faqs_{$property->id}", 120, fn () => $property->faqs()->where('is_active', true)->get(['id', 'category', 'question', 'answer', 'order']));

        return response()->json(['success' => true, 'data' => $faqs]);
    }

    /**
     * Lightweight polling (cPanel: no WebSockets). Returns 204 when nothing changed so an idle
     * widget costs one indexed query per poll.
     */
    public function syncMessages(Request $request, string $id): JsonResponse
    {
        $conversation = WidgetAccess::conversation($request, $id, (string) $request->query('visitor_token'));
        if (! $conversation) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        if (! WidgetOrigin::allows($request, $conversation->property)) {
            return $this->forbiddenOrigin();
        }

        $afterSeq = $request->query('after_seq');
        if ($afterSeq === null && $request->filled('after_id')) {
            $afterSeq = Message::where('id', $request->query('after_id'))->where('conversation_id', $conversation->id)->value('seq');
        }

        $query = $conversation->messages()->visibleToCustomer();
        if ($afterSeq !== null && is_numeric($afterSeq)) {
            $query->where('seq', '>', (int) $afterSeq);
        }
        $newMessages = $query->take(50)->get();

        $visitorId = $conversation->visitor_id;
        Visitor::where('id', $visitorId)->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds(30)))
            ->update(['last_seen_at' => now()]);

        $assignedAgent = null;
        if ($conversation->assigned_agent_id && ($agent = User::find($conversation->assigned_agent_id))) {
            $assignedAgent = ['id' => $agent->id, 'name' => $agent->name, 'role' => $agent->role, 'avatar' => strtoupper(substr($agent->name, 0, 2))];
        }
        $openTicket = Ticket::where('open_conversation_id', $conversation->id)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'messages' => $newMessages,
                'last_seq' => (int) $conversation->last_seq,
                'status' => $conversation->status,
                'handler' => $conversation->handler,
                'ai_pending' => $conversation->ai_pending_since !== null && $conversation->ai_pending_since->gt(now()->subMinutes(3)),
                'open_ticket' => $openTicket?->toCustomerArray(),
                'assigned_agent' => $assignedAgent,
                'started_at' => $conversation->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_key' => ['required', 'string', 'max:100'],
            'visitor_token' => ['required', 'string', 'max:100'],
        ]);
        $property = $this->activePropertyByKey($validated['property_key']);
        if ($property) {
            Visitor::where('property_id', $property->id)->where('visitor_token', $validated['visitor_token'])->update(['last_seen_at' => now()]);
        }

        return response()->json(['success' => true]);
    }

    public function leave(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $propertyKey = $data['property_key'] ?? $request->input('property_key');
        $visitorToken = $data['visitor_token'] ?? $request->input('visitor_token');
        if (is_string($propertyKey) && is_string($visitorToken) && ($property = $this->activePropertyByKey($propertyKey))) {
            Visitor::where('property_id', $property->id)->where('visitor_token', $visitorToken)->update(['last_seen_at' => now()->subMinutes(5)]);
        }

        return response()->json(['success' => true]);
    }

    public function startConversation(Request $request): JsonResponse
    {
        if ($request->filled('_bot_trap') || $request->filled('website_hp')) {
            Log::warning("AntiBotShield: Bot honeypot triggered on startConversation from IP: {$request->ip()}");

            return response()->json(['success' => false, 'message' => 'Automated bot request rejected.'], 403);
        }

        $validated = $request->validate([
            'property_key' => ['required', 'string', 'max:100'],
            'visitor_token' => ['required', 'string', 'max:100'],
            'department_id' => ['nullable', 'string'],
            'initial_message' => ['required', 'string', 'max:2000'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'client_message_id' => ['nullable', 'string', 'max:64'],
            '_bot_trap' => ['nullable', 'string'],
        ]);

        $property = $this->activePropertyByKey($validated['property_key']);
        abort_unless($property, 404);
        if (! WidgetOrigin::allows($request, $property)) {
            return $this->forbiddenOrigin();
        }

        $customer = CustomerAuthService::resolve($request, $property);
        if (($property->identity_mode ?? 'guest_only') === 'pin_required' && ! $customer) {
            return response()->json(['success' => false, 'code' => 'customer_unauthenticated', 'message' => 'Please sign in with your e-mail and Support PIN to start a chat.'], 401);
        }

        $departmentId = null;
        if (! empty($validated['department_id'])) {
            $departmentId = Department::where('id', $validated['department_id'])->where('property_id', $property->id)->where('is_active', true)->value('id');
            if (! $departmentId) {
                return response()->json(['success' => false, 'message' => 'The selected department is invalid.', 'errors' => ['department_id' => ['The selected department is invalid.']]], 422);
            }
        }

        $visitor = Visitor::where('property_id', $property->id)->where('visitor_token', $validated['visitor_token'])->firstOrFail();
        if ($visitor->is_blocked) {
            return response()->json(['success' => false, 'message' => 'Blocked.'], 403);
        }

        $limits = \App\Services\PlanLimits::limits($property->tenant_id);
        $monthlyCap = $limits['limits']['conversations_per_month'] ?? null;
        if ($limits['status'] === 'cancelled' || ($monthlyCap !== null && \App\Services\PlanLimits::usage($property->tenant_id)['conversations_per_month'] >= $monthlyCap)) {
            return response()->json(['success' => false, 'code' => 'plan_limit', 'message' => 'Live chat is temporarily unavailable. Please contact us by e-mail.'], 503);
        }

        $activeCount = Conversation::whereIn('status', ['unassigned', 'open', 'pending'])
            ->when($customer, fn ($q) => $q->where('customer_account_id', $customer->id), fn ($q) => $q->where('visitor_id', $visitor->id))
            ->count();
        if ($activeCount >= 3) {
            return response()->json(['success' => false, 'message' => 'You already have active open chat sessions. Please continue in your existing window.'], 429);
        }

        if ($name = SafeText::field($validated['name'] ?? null)) {
            $visitor->name = $name;
        }
        if (! empty($validated['email'])) {
            $visitor->email = mb_strtolower(trim($validated['email']));
        }
        if ($phone = SafeText::field($validated['phone'] ?? null, 50)) {
            $visitor->phone = $phone;
        }

        $content = SafeText::message($validated['initial_message']);
        if ($content === '') {
            return response()->json(['success' => false, 'message' => 'Message cannot be empty.'], 422);
        }

        $meta = $visitor->metadata ?? [];
        $meta['lead_status'] ??= 'new';
        $meta['initial_inquiry'] = mb_substr($content, 0, 1000);
        $visitor->metadata = $meta;
        $visitor->last_seen_at = now();
        $visitor->save();

        $conversation = Conversation::create([
            'property_id' => $property->id,
            'visitor_id' => $visitor->id,
            'customer_account_id' => $customer?->id,
            'department_id' => $departmentId,
            'status' => 'unassigned',
            'handler' => 'ai',
            'last_message_at' => now(),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'visitor',
            'message_type' => 'text',
            'content' => $content,
            'client_message_id' => $validated['client_message_id'] ?? null,
            'is_read' => false,
        ]);

        $brand = $this->brandName($property);
        $greeting = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'bot',
            'message_type' => 'text',
            'is_automated' => true,
            'content' => "Hello! Thank you for contacting {$brand} Support. 💬",
            'is_read' => true,
        ]);

        LeadCaptureService::capture($visitor, $conversation, [
            'inquiry' => $content,
            'email' => $visitor->email ?: self::emailIn($content),
        ]);

        $department = $departmentId ? Department::find($departmentId) : null;
        if ($department && str_contains(mb_strtolower($department->name), 'sales')) {
            Notifier::salesInquiry($conversation);
        }

        $botReply = SupportBot::handle($conversation, $message);

        $conversation->refresh()->load(['visitor', 'department']);
        $this->safeBroadcast(fn () => broadcast(new ConversationCreated($conversation))->toOthers());
        $this->safeBroadcast(fn () => broadcast(new MessageSent($message))->toOthers());

        return response()->json([
            'success' => true,
            'message' => 'Conversation started.',
            'data' => [
                'conversation' => $this->conversationPayload($conversation),
                'message' => $message,
                'bot_reply' => $botReply ?? $greeting,
            ],
        ], 201);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        if ($request->filled('_bot_trap')) {
            Log::warning("AntiBotShield: Bot honeypot triggered on sendMessage from IP: {$request->ip()}");

            return response()->json(['success' => false, 'message' => 'Automated bot request rejected.'], 403);
        }

        $validated = $request->validate([
            'property_key' => ['required', 'string', 'max:100'],
            'visitor_token' => ['required', 'string', 'max:100'],
            'conversation_id' => ['required', 'string'],
            'content' => ['nullable', 'string', 'max:3000'],
            'message_type' => ['nullable', 'string', 'in:text,image,file'],
            'attachment_id' => ['nullable', 'string', 'max:64'],
            'attachment_url' => ['nullable', 'string', 'max:2000'],
            'client_message_id' => ['nullable', 'string', 'max:64'],
            '_bot_trap' => ['nullable', 'string'],
        ]);

        $content = SafeText::message($validated['content'] ?? null);
        if ($content === '' && empty($validated['attachment_url']) && empty($validated['attachment_id'])) {
            return response()->json(['success' => false, 'message' => 'Message content or attachment is required.'], 422);
        }

        $property = $this->activePropertyByKey($validated['property_key']);
        abort_unless($property, 404);
        if (! WidgetOrigin::allows($request, $property)) {
            return $this->forbiddenOrigin();
        }

        // The device's own visitor record (used for attachment ownership and presence).
        $visitor = Visitor::where('property_id', $property->id)->where('visitor_token', $validated['visitor_token'])->firstOrFail();
        if ($visitor->is_blocked) {
            return response()->json(['success' => false, 'message' => 'Blocked.'], 403);
        }

        $conversation = WidgetAccess::conversation($request, $validated['conversation_id'], $validated['visitor_token'], $property->id);
        if (! $conversation) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        // Idempotent retries: the same client_message_id returns the already stored message.
        if (! empty($validated['client_message_id'])
            && ($existing = Message::where('conversation_id', $conversation->id)->where('client_message_id', $validated['client_message_id'])->first())) {
            return response()->json(['success' => true, 'data' => $existing, 'bot_reply' => null, 'duplicate' => true], 200);
        }

        // Anti-spam flood guard: reject identical repeated messages sent rapidly in 15 seconds.
        if ($content !== '') {
            $recentSame = Message::where('conversation_id', $conversation->id)
                ->where('sender_type', 'visitor')
                ->where('content', $content)
                ->where('created_at', '>=', now()->subSeconds(15))
                ->count();
            if ($recentSame >= 3) {
                return response()->json(['success' => false, 'message' => 'Please wait a moment before sending the same message repeatedly.'], 429);
            }
        }

        [$attachmentRef, $attachmentType] = AttachmentService::resolveForMessage(
            $validated['attachment_id'] ?? null,
            $validated['attachment_url'] ?? null,
            $conversation,
            'visitor',
            $visitor->id
        );

        if ($conversation->status === 'closed') {
            $conversation->status = 'open';
        }
        $conversation->last_message_at = now();
        $conversation->save();
        $visitor->update(['last_seen_at' => now()]);

        if ($content === '') {
            $content = $attachmentType === 'image' ? 'Sent an image attachment' : 'Sent a file attachment';
        }

        try {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'visitor',
                'sender_id' => null,
                'message_type' => $attachmentType ?? 'text',
                'content' => $content,
                'attachment_url' => $attachmentRef,
                'client_message_id' => $validated['client_message_id'] ?? null,
                'is_read' => false,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Two identical retries arrived at the same moment: the first one won; return it.
            $existing = Message::where('conversation_id', $conversation->id)->where('client_message_id', $validated['client_message_id'] ?? '')->first();
            if (! $existing) {
                throw $e;
            }

            return response()->json(['success' => true, 'data' => $existing, 'bot_reply' => null, 'duplicate' => true], 200);
        }

        $this->safeBroadcast(fn () => broadcast(new MessageSent($message))->toOthers());

        WebhookService::dispatch($property, 'message.sent', [
            'conversation_id' => $conversation->id,
            'sender_type' => 'visitor',
            'sender_name' => $visitor->name,
            'content' => $content,
            'has_attachment' => $attachmentRef !== null,
            'message_type' => $message->message_type,
        ]);

        LeadCaptureService::capture($visitor, $conversation, [
            'inquiry' => $attachmentRef ? null : $content,
            'email' => $visitor->email ?: self::emailIn($content),
        ]);
        Notifier::customerReplied($conversation);
        TicketService::customerReplied($conversation);

        $botMessage = $attachmentRef ? null : SupportBot::handle($conversation, $message);

        return response()->json(['success' => true, 'data' => $message, 'bot_reply' => $botMessage], 201);
    }

    /**
     * "Talk to a human" button: live handover when staff are online, otherwise a ticket.
     * Idempotent per idempotency_key (double taps / retries return the same ticket).
     */
    public function escalate(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'visitor_token' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);
        $conversation = WidgetAccess::conversation($request, $id, $validated['visitor_token'] ?? null);
        if (! $conversation) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        if (! WidgetOrigin::allows($request, $conversation->property)) {
            return $this->forbiddenOrigin();
        }

        $lang = $conversation->language ?? 'en';
        try {
            // Key scoped to this conversation, so a reused key can never return another chat's ticket.
            $result = ConversationService::requestHuman($conversation, 'esc-' . substr(hash('sha256', $conversation->id . '|' . $validated['idempotency_key']), 0, 48), 'customer_request');
        } catch (\Throwable $e) {
            Log::error('Widget escalation failed: ' . $e->getMessage());

            return response()->json(['success' => false, 'retry' => true, 'message' => SupportBot::t('ticket_failed', $lang)], 503);
        }

        if ($result['mode'] === 'live') {
            SupportBot::botReply($conversation, SupportBot::t('human_live', $lang), ['source' => 'handover'], true);
        } elseif ($result['created']) {
            SupportBot::botReply($conversation, SupportBot::t('ticket_created', $lang, ['ref' => $result['ticket']->reference]) . SupportBot::hoursNote($conversation->property, $lang), ['source' => 'ticket', 'ticket_id' => $result['ticket']->id], true);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'mode' => $result['mode'],
                'ticket' => $result['ticket']?->toCustomerArray(),
                'created' => $result['created'],
            ],
        ]);
    }

    public function typing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'string'],
            'visitor_token' => ['required', 'string', 'max:100'],
            'is_typing' => ['required', 'boolean'],
        ]);
        $conversation = WidgetAccess::conversation($request, $validated['conversation_id'], $validated['visitor_token']);
        if (! $conversation) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }
        $this->safeBroadcast(fn () => broadcast(new TypingEvent($conversation->id, 'visitor', $conversation->visitor->name ?? 'Visitor', $validated['is_typing']))->toOthers());

        return response()->json(['success' => true]);
    }

    /** Optional realtime server auth (cPanel deployments use polling and never call this). */
    public function authBroadcast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'socket_id' => ['required', 'string', 'max:100'],
            'channel_name' => ['required', 'string', 'max:200'],
            'visitor_token' => ['required', 'string', 'max:100'],
        ]);
        $parts = explode('.', str_replace('private-', '', $validated['channel_name']));
        if (count($parts) !== 2 || $parts[0] !== 'chat') {
            return response()->json(['error' => 'Invalid channel.'], 400);
        }
        if (! WidgetAccess::conversation($request, $parts[1], $validated['visitor_token'])) {
            return response()->json(['error' => 'Unauthorized channel access.'], 403);
        }

        $appKey = config('reverb.apps.apps.0.key');
        $appSecret = config('reverb.apps.apps.0.secret');
        $appId = config('reverb.apps.apps.0.app_id');
        if (config('broadcasting.default') !== 'reverb' || ! $appKey || ! $appSecret || ! $appId) {
            return response()->json(['error' => 'Realtime server not configured.'], 503);
        }
        $pusher = new Pusher($appKey, $appSecret, $appId, [
            'host' => config('reverb.apps.apps.0.options.host'),
            'port' => (int) config('reverb.apps.apps.0.options.port'),
            'scheme' => config('reverb.apps.apps.0.options.scheme'),
        ]);

        return response()->json(json_decode($pusher->authorizeChannel($validated['channel_name'], $validated['socket_id']), true));
    }

    public function status(Request $request): JsonResponse
    {
        $propertyKey = (string) $request->query('property_key');
        if ($propertyKey === '') {
            return response()->json(['success' => false, 'message' => 'Property key required.'], 400);
        }
        $property = $this->activePropertyByKey($propertyKey);
        if (! $property) {
            return response()->json(['success' => false, 'message' => 'Property not found.'], 404);
        }

        $config = $property->widget_config ?? [];
        $isWithinHours = BusinessHours::isOpen($property);
        $onlineAgentsCount = User::where('tenant_id', $property->tenant_id)->where('is_online', true)
            ->where('last_active_at', '>=', now()->subMinutes(15))->count();
        $cfg = BusinessHours::config($property);

        return response()->json([
            'success' => true,
            'is_online' => $isWithinHours && $onlineAgentsCount > 0,
            'online_agents' => $onlineAgentsCount,
            'is_within_hours' => $isWithinHours,
            'working_hours' => "{$cfg['start']}–{$cfg['end']} ({$cfg['timezone']})",
            'offline_notice' => $config['operating_hours']['offline_notice'] ?? "Our team works {$cfg['start']}–{$cfg['end']} IST every day. Leave your message and we will reply in this chat — you can also follow it under \"My Tickets\".",
            'csat_enabled' => $config['csat_enabled'] ?? true,
            'attachments_enabled' => $config['attachments_enabled'] ?? true,
            'identity_mode' => $property->identity_mode ?? 'guest_only',
        ]);
    }

    /** Working-hours check that also supports overnight ranges such as 22:00–06:00 (audit B6). */
    public static function isWithinHours(string $now, string $start, string $end): bool
    {
        if ($start === $end) {
            return true;
        }
        if ($start < $end) {
            return $now >= $start && $now < $end;
        }

        return $now >= $start || $now < $end;
    }

    public function uploadAttachment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_key' => ['required', 'string', 'max:100'],
            'visitor_token' => ['required', 'string', 'max:100'],
            'conversation_id' => ['required', 'string', 'max:64'],
            'file' => ['required', 'file', 'max:' . (int) config('chatbox.attachments.max_kb', 10240)],
        ]);

        $property = $this->activePropertyByKey($validated['property_key']);
        abort_unless($property, 404);
        if (! WidgetOrigin::allows($request, $property)) {
            return $this->forbiddenOrigin();
        }
        if (($property->widget_config['attachments_enabled'] ?? true) === false) {
            return response()->json(['success' => false, 'message' => 'Attachments are disabled for this website.'], 403);
        }
        $visitor = Visitor::where('property_id', $property->id)->where('visitor_token', $validated['visitor_token'])->firstOrFail();
        if ($visitor->is_blocked) {
            return response()->json(['success' => false, 'message' => 'Access denied: Visitor is blocked.'], 403);
        }

        // Uploads belong to the visitor's own open chat (no free file hosting without a conversation).
        $conversation = WidgetAccess::conversation($request, $validated['conversation_id'], $validated['visitor_token'], $property->id);
        abort_unless($conversation, 404);

        $attachment = AttachmentService::store($request->file('file'), $property->tenant_id, $property->id, 'visitor', $visitor->id, $conversation->id);

        return response()->json([
            'success' => true,
            'attachment_id' => $attachment->id,
            'url' => $attachment->signedUrl(),
            'file_name' => $attachment->original_name,
            'message_type' => $attachment->isImage() ? 'image' : 'file',
        ]);
    }

    public function submitRating(Request $request, string $conversationId): JsonResponse
    {
        $validated = $request->validate([
            'property_key' => ['required', 'string', 'max:100'],
            'visitor_token' => ['required', 'string', 'max:100'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'rating_feedback' => ['nullable', 'string', 'max:1000'],
        ]);
        $property = $this->activePropertyByKey($validated['property_key']);
        abort_unless($property, 404);
        if (! WidgetOrigin::allows($request, $property)) {
            return $this->forbiddenOrigin();
        }
        $conversation = WidgetAccess::conversation($request, $conversationId, $validated['visitor_token'], $property->id);
        abort_unless($conversation, 404);

        $feedback = isset($validated['rating_feedback']) ? SafeText::message($validated['rating_feedback']) : null;
        $conversation->update(['rating' => $validated['rating'], 'rating_feedback' => $feedback]);

        WebhookService::dispatch($property, 'rating.submitted', [
            'conversation_id' => $conversation->id,
            'rating' => $validated['rating'],
            'feedback' => $feedback ?? '',
        ]);

        return response()->json(['success' => true, 'message' => 'Thank you! Your feedback has been recorded.', 'rating' => $validated['rating']]);
    }

    public function requestTranscript(Request $request, string $conversationId): JsonResponse
    {
        $validated = $request->validate([
            'property_key' => ['required', 'string', 'max:100'],
            'visitor_token' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        $property = $this->activePropertyByKey($validated['property_key']);
        abort_unless($property, 404);
        if (! WidgetOrigin::allows($request, $property)) {
            return $this->forbiddenOrigin();
        }
        $conversation = WidgetAccess::conversation($request, $conversationId, $validated['visitor_token'], $property->id);
        abort_unless($conversation, 404);

        $convKey = 'transcript:conv:' . $conversation->id;
        $ipKey = 'transcript:ip:' . $request->ip();
        if (RateLimiter::tooManyAttempts($convKey, (int) config('chatbox.transcripts.per_conversation_per_hour', 3))
            || RateLimiter::tooManyAttempts($ipKey, (int) config('chatbox.transcripts.per_ip_per_day', 10))) {
            return response()->json(['success' => false, 'sent' => false, 'message' => 'Too many transcript requests. Please try again later.'], 429);
        }
        // Security (browser F4 / logic F12): transcripts go only to the e-mail saved for this chat
        // (the signed-in customer's account or the visitor's own e-mail), at most once per day per
        // recipient, so the chat cannot be used to send branded e-mails to arbitrary people.
        $known = array_filter([
            $conversation->customerAccount?->email,
            $conversation->visitor?->email,
        ]);
        $to = mb_strtolower(trim($validated['email']));
        if (! in_array($to, array_map('mb_strtolower', $known), true)) {
            return response()->json(['success' => false, 'sent' => false, 'message' => 'The transcript can only be sent to the e-mail address saved for this chat.'], 422);
        }
        $recipientKey = 'transcript:to:' . $property->id . ':' . hash('sha256', $to);
        if (RateLimiter::tooManyAttempts($recipientKey, 1)) {
            return response()->json(['success' => false, 'sent' => false, 'message' => 'A transcript was already sent to this address today.'], 429);
        }
        RateLimiter::hit($recipientKey, 86400);
        RateLimiter::hit($convKey, 3600);
        RateLimiter::hit($ipKey, 86400);

        $result = TranscriptService::sendTranscript($conversation, $validated['email']);

        return response()->json($result, $result['success'] ? 200 : 503);
    }

    // ------------------------------------------------------------------

    private function conversationPayload(Conversation $conversation): array
    {
        $data = $conversation->only(['id', 'property_id', 'department_id', 'status', 'handler', 'rating', 'created_at', 'last_message_at', 'last_seq']);
        $data['messages'] = $conversation->messages()->visibleToCustomer()->reorder()->orderByDesc('seq')->limit(300)->get()->sortBy('seq')->values(); // latest 300 only
        $data['open_ticket'] = Ticket::where('open_conversation_id', $conversation->id)->first()?->toCustomerArray();
        $data['ai_pending'] = $conversation->ai_pending_since !== null;

        return $data;
    }

    private function activePropertyByKey(string $key): ?Property
    {
        if ($key === '') {
            return null;
        }

        return Property::activeByPublicKey($key);
    }

    private static function emailIn(string $text): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m) && filter_var($m[0], FILTER_VALIDATE_EMAIL)) {
            return mb_strtolower($m[0]);
        }

        return null;
    }

    private function forbiddenOrigin(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => '403 Forbidden: Unauthorized Domain Origin. Your domain is not whitelisted for this widget.'], 403);
    }

    private function brandName(Property $property): string
    {
        $name = trim((string) ($property->widget_config['brand_name'] ?? $property->site_name));

        return $name !== '' ? $name : 'our';
    }

    /** Realtime is optional on cPanel: a failing broadcast must never break the request (audit B3). */
    private function safeBroadcast(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::debug('Realtime broadcast skipped: ' . $e->getMessage());
        }
    }
}
