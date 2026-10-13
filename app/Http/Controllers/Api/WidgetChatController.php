<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationCreated;
use App\Events\MessageSent;
use App\Events\TypingEvent;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Faq;
use App\Models\Message;
use App\Models\Property;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pusher\Pusher;

class WidgetChatController extends Controller
{
    /**
     * Initialize widget for a website visitor.
     * Verifies domain whitelisting and returns active session data.
     */
    public function init(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_key' => ['required', 'string'],
            'visitor_token' => ['nullable', 'string'],
            'origin' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'current_page_url' => ['nullable', 'string'],
            'browser' => ['nullable', 'string', 'max:100'],
            'os' => ['nullable', 'string', 'max:100'],
        ]);

        $property = Cache::remember("prop_pub_{$validated['property_key']}", 120, function () use ($validated) {
            return Property::where('public_key', $validated['property_key'])
                ->where('is_active', true)
                ->first();
        });

        if (! $property) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or inactive property key.',
            ], 404);
        }

        // Domain Whitelisting Verification
        $requestOrigin = $validated['origin'] ?? $request->header('Origin') ?? $request->header('Referer');
        if (! empty($property->allowed_domains) && ! $property->isOriginAllowed($requestOrigin)) {
            return response()->json([
                'success' => false,
                'message' => '403 Forbidden: Unauthorized Domain Origin. Your domain is not whitelisted for this widget.',
            ], 403);
        }

        // Find or create visitor
        $visitorToken = $validated['visitor_token'] ?? ('vtok_' . Str::random(32));
        $ip = $request->ip();

        $visitor = Visitor::firstOrNew([
            'property_id' => $property->id,
            'visitor_token' => $visitorToken,
        ]);

        if ($visitor->is_blocked) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied: This visitor has been blocked.',
            ], 403);
        }

        $visitor->name = $validated['name'] ?? $visitor->name;
        $visitor->email = $validated['email'] ?? $visitor->email;
        $visitor->phone = $validated['phone'] ?? $visitor->phone;
        $visitor->ip_address = $ip;
        $visitor->browser = $validated['browser'] ?? $visitor->browser;
        $visitor->os = $validated['os'] ?? $visitor->os;
        $visitor->current_page_url = $validated['current_page_url'] ?? $visitor->current_page_url;
        $visitor->last_seen_at = now();
        $visitor->save();

        // Check for existing active conversation
        $conversation = Conversation::where('property_id', $property->id)
            ->where('visitor_id', $visitor->id)
            ->whereIn('status', ['unassigned', 'open', 'pending'])
            ->with(['messages', 'assignedAgent', 'department'])
            ->latest('last_message_at')
            ->first();

        // Departments for pre-chat selection (Cached for high concurrency)
        $departments = Cache::remember("prop_depts_{$property->id}", 120, function () use ($property) {
            return $property->departments()->where('is_active', true)->get(['id', 'name', 'description']);
        });

        // Company FAQs for self-service help (Cached for high concurrency)
        $faqs = Cache::remember("prop_faqs_{$property->id}", 120, function () use ($property) {
            return $property->faqs()->where('is_active', true)->get(['id', 'category', 'question', 'answer', 'order']);
        });

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
                    'widget_config' => $property->widget_config,
                ],
                'departments' => $departments,
                'faqs' => $faqs,
                'conversation' => $conversation,
                'broadcasting' => [
                    'key' => config('reverb.apps.apps.0.key', env('REVERB_APP_KEY')),
                    'host' => config('reverb.apps.apps.0.options.host', env('REVERB_HOST', 'localhost')),
                    'port' => (int) config('reverb.apps.apps.0.options.port', env('REVERB_PORT', 8080)),
                    'scheme' => config('reverb.apps.apps.0.options.scheme', env('REVERB_SCHEME', 'http')),
                ],
            ],
        ]);
    }

    /**
     * Fetch active FAQs for property.
     */
    public function faqs(Request $request): JsonResponse
    {
        $propertyKey = $request->query('property_key');
        $property = Property::where('public_key', $propertyKey)->where('is_active', true)->firstOrFail();

        $faqs = Cache::remember("prop_faqs_{$property->id}", 120, function () use ($property) {
            return $property->faqs()->where('is_active', true)->get();
        });

        return response()->json([
            'success' => true,
            'data' => $faqs,
        ]);
    }

    /**
     * Ultra-fast lightweight message sync for polling fallback (Zero overhead, minimal DB load).
     */
    public function syncMessages(Request $request, string $id): JsonResponse
    {
        $token = $request->query('visitor_token');
        $afterId = $request->query('after_id');

        $conversation = Conversation::where('id', $id)
            ->whereHas('visitor', fn($q) => $q->where('visitor_token', $token))
            ->first();

        if (! $conversation) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $query = $conversation->messages()->orderBy('created_at', 'asc');
        if (! empty($afterId)) {
            $lastMsg = Message::find($afterId);
            if ($lastMsg) {
                $query->where('created_at', '>', $lastMsg->created_at);
            }
        }

        $newMessages = $query->take(20)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'messages' => $newMessages,
                'status' => $conversation->status,
            ],
        ]);
    }

    /**
     * Start a new conversation from the widget.
     */
    public function startConversation(Request $request): JsonResponse
    {
        // 1. Anti-Bot Honeypot Shield Check
        if ($request->filled('_bot_trap') || $request->filled('website_hp')) {
            Log::warning("AntiBotShield: Bot honeypot triggered on startConversation from IP: {$request->ip()}");
            return response()->json(['success' => false, 'message' => 'Automated bot request rejected.'], 403);
        }

        $validated = $request->validate([
            'property_key' => ['required', 'string'],
            'visitor_token' => ['required', 'string'],
            'department_id' => ['nullable', 'string', 'exists:departments,id'],
            'initial_message' => ['required', 'string', 'max:2000'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            '_bot_trap' => ['nullable', 'string'],
        ]);

        $property = Property::where('public_key', $validated['property_key'])->where('is_active', true)->firstOrFail();
        
        $visitor = Visitor::where('property_id', $property->id)
            ->where('visitor_token', $validated['visitor_token'])
            ->firstOrFail();

        if ($visitor->is_blocked) {
            return response()->json(['success' => false, 'message' => 'Blocked.'], 403);
        }

        // 2. Limit concurrent active open conversations per visitor (prevent bot exhaustion)
        $activeCount = Conversation::where('visitor_id', $visitor->id)
            ->whereIn('status', ['unassigned', 'open', 'pending'])
            ->count();
        if ($activeCount >= 3) {
            return response()->json([
                'success' => false,
                'message' => 'You already have active open chat sessions. Please continue in your existing window.',
            ], 429);
        }

        if (! empty($validated['name'])) {
            $visitor->name = $validated['name'];
        }
        if (! empty($validated['email'])) {
            $visitor->email = $validated['email'];
        }
        if ($request->filled('phone')) {
            $visitor->phone = $request->phone;
        }

        // Clean & sanitize initial message
        $sanitizedContent = htmlspecialchars(trim(strip_tags($validated['initial_message'])), ENT_QUOTES, 'UTF-8');

        $meta = $visitor->metadata ?? [];
        if (! isset($meta['lead_status'])) {
            $meta['lead_status'] = 'new';
        }
        $meta['initial_inquiry'] = $sanitizedContent;
        $visitor->metadata = $meta;
        $visitor->last_seen_at = now();
        $visitor->save();

        // Create new conversation
        $conversation = Conversation::create([
            'property_id' => $property->id,
            'visitor_id' => $visitor->id,
            'department_id' => $validated['department_id'] ?? null,
            'status' => 'unassigned',
            'last_message_at' => now(),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'visitor',
            'message_type' => 'text',
            'content' => $sanitizedContent,
            'is_read' => false,
        ]);

        // Intelligent FAQ / Knowledge Base Matcher & Human Agent Dispatch
        $botReplyContent = null;
        if ($this->wantsHumanAgent($sanitizedContent)) {
            $botReplyContent = "🤝 Connecting you with our live support team... An agent will be with you shortly!";
        } else {
            $matchedFaq = $this->findFaqMatch($property, $sanitizedContent);
            if ($matchedFaq) {
                $botReplyContent = $matchedFaq->answer . "\n\n💡 _Need to speak with a human support agent? Reply 'Talk to agent' anytime._";
            } else {
                $botReplyContent = "👋 Thanks for contacting us! We've received your inquiry and our support team has been notified.";
            }
        }

        if ($botReplyContent) {
            $botMessage = Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'bot',
                'message_type' => 'text',
                'content' => $botReplyContent,
                'is_read' => true,
            ]);

            try {
                broadcast(new MessageSent($botMessage))->toOthers();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('WebSocket broadcast skipped for bot message: ' . $e->getMessage());
            }
        }

        $conversation->load(['visitor', 'department', 'messages']);

        // Realtime Event: Alert all online agents in this property
        try {
            broadcast(new ConversationCreated($conversation))->toOthers();
            broadcast(new MessageSent($message))->toOthers();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('WebSocket broadcast skipped (Reverb offline): ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation started.',
            'data' => [
                'conversation' => $conversation,
                'message' => $message,
                'bot_reply' => $botMessage ?? null,
            ],
        ], 201);
    }

    /**
     * Send a message from the visitor widget.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        // Anti-Bot Honeypot Shield Check
        if ($request->filled('_bot_trap')) {
            Log::warning("AntiBotShield: Bot honeypot triggered on sendMessage from IP: {$request->ip()}");
            return response()->json(['success' => false, 'message' => 'Automated bot request rejected.'], 403);
        }

        $validated = $request->validate([
            'property_key' => ['required', 'string'],
            'visitor_token' => ['required', 'string'],
            'conversation_id' => ['required', 'string', 'exists:conversations,id'],
            'content' => ['required', 'string', 'max:3000'],
            'message_type' => ['nullable', 'string', 'in:text,image,file'],
            '_bot_trap' => ['nullable', 'string'],
        ]);

        $property = Property::where('public_key', $validated['property_key'])->where('is_active', true)->firstOrFail();

        $visitor = Visitor::where('property_id', $property->id)
            ->where('visitor_token', $validated['visitor_token'])
            ->firstOrFail();

        if ($visitor->is_blocked) {
            return response()->json(['success' => false, 'message' => 'Blocked.'], 403);
        }

        $conversation = Conversation::where('id', $validated['conversation_id'])
            ->where('property_id', $property->id)
            ->where('visitor_id', $visitor->id)
            ->firstOrFail();

        // If conversation was closed, reopen it
        if ($conversation->status === 'closed') {
            $conversation->status = 'open';
        }
        $conversation->last_message_at = now();
        $conversation->save();

        // Anti-XSS Sanitization
        $cleanContent = htmlspecialchars(trim(strip_tags($validated['content'])), ENT_QUOTES, 'UTF-8');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'visitor',
            'sender_id' => null,
            'message_type' => $validated['message_type'] ?? 'text',
            'content' => $cleanContent,
            'is_read' => false,
        ]);

        $message->load('conversation.visitor');

        // Broadcast to agents on private-chat.{conversationId}
        try {
            broadcast(new MessageSent($message))->toOthers();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('WebSocket broadcast skipped: ' . $e->getMessage());
        }

        // If no human agent is actively assigned yet, check for automated FAQ answer
        if (empty($conversation->assigned_agent_id)) {
            $botReplyContent = null;
            if ($this->wantsHumanAgent($cleanContent)) {
                $botReplyContent = "🤝 An online support agent has been notified and will respond here shortly. Please stay connected!";
            } else {
                $matchedFaq = $this->findFaqMatch($property, $cleanContent);
                if ($matchedFaq) {
                    $botReplyContent = $matchedFaq->answer . "\n\n💡 _Need to speak with a human support agent? Reply 'Talk to agent' anytime._";
                }
            }

            if ($botReplyContent) {
                $botMessage = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_type' => 'bot',
                    'message_type' => 'text',
                    'content' => $botReplyContent,
                    'is_read' => true,
                ]);

                try {
                    broadcast(new MessageSent($botMessage))->toOthers();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('WebSocket broadcast skipped for bot message: ' . $e->getMessage());
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $message,
            'bot_reply' => $botMessage ?? null,
        ], 201);
    }

    /**
     * Visitor typing indicator trigger.
     */
    public function typing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'string', 'exists:conversations,id'],
            'visitor_token' => ['required', 'string'],
            'is_typing' => ['required', 'boolean'],
        ]);

        $conversation = Conversation::with('visitor')->findOrFail($validated['conversation_id']);

        if ($conversation->visitor->visitor_token !== $validated['visitor_token']) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        try {
            broadcast(new TypingEvent(
                $conversation->id,
                'visitor',
                $conversation->visitor->name ?? 'Visitor',
                $validated['is_typing']
            ))->toOthers();
        } catch (\Throwable $e) {}

        return response()->json(['success' => true]);
    }

    /**
     * Authenticate visitor for private broadcasting channel (chat.{conversationId}).
     */
    public function authBroadcast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'socket_id' => ['required', 'string'],
            'channel_name' => ['required', 'string'],
            'visitor_token' => ['required', 'string'],
        ]);

        // Channel name is expected as private-chat.{conversationId}
        $channelName = $validated['channel_name'];
        $cleanChannel = str_replace('private-', '', $channelName);
        $parts = explode('.', $cleanChannel);

        if (count($parts) !== 2 || $parts[0] !== 'chat') {
            return response()->json(['error' => 'Invalid channel.'], 400);
        }

        $conversationId = $parts[1];
        $conversation = Conversation::with('visitor')->find($conversationId);

        if (! $conversation || $conversation->visitor->visitor_token !== $validated['visitor_token']) {
            return response()->json(['error' => 'Unauthorized channel access.'], 403);
        }

        // Generate Pusher/Reverb Auth signature
        $appKey = config('reverb.apps.apps.0.key', env('REVERB_APP_KEY'));
        $appSecret = config('reverb.apps.apps.0.secret', env('REVERB_APP_SECRET'));
        $appId = config('reverb.apps.apps.0.app_id', env('REVERB_APP_ID'));

        $pusher = new Pusher($appKey, $appSecret, $appId, [
            'host' => config('reverb.apps.apps.0.options.host', env('REVERB_HOST', 'localhost')),
            'port' => (int) config('reverb.apps.apps.0.options.port', env('REVERB_PORT', 8080)),
            'scheme' => config('reverb.apps.apps.0.options.scheme', env('REVERB_SCHEME', 'http')),
        ]);

        $auth = $pusher->authorizeChannel($channelName, $validated['socket_id']);

        return response()->json(json_decode($auth, true));
    }

    /**
     * Check if visitor inquiry specifically requests human support assistance.
     */
    protected function wantsHumanAgent(string $query): bool
    {
        $lowered = mb_strtolower($query);
        $humanKeywords = ['agent', 'human', 'live chat', 'support executive', 'customer care', 'representative', 'real person', 'baat karni hai', 'insan', 'executive', 'call me', 'help me live'];
        foreach ($humanKeywords as $kw) {
            if (mb_strpos($lowered, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find best matching Knowledge Base FAQ for the property based on keywords.
     */
    protected function findFaqMatch(Property $property, string $query): ?Faq
    {
        $faqs = Cache::remember("prop_faqs_all_{$property->id}", 60, function () use ($property) {
            return $property->faqs()->where('is_active', true)->get();
        });

        if ($faqs->isEmpty()) {
            return null;
        }

        $cleanQuery = mb_strtolower(trim($query));
        $cleanQueryWords = preg_split('/[\s,\.\?!;:\(\)\-\_\/\+]+/u', $cleanQuery, -1, PREG_SPLIT_NO_EMPTY);

        $stopWords = [
            'kya', 'hai', 'kaise', 'karein', 'mera', 'meri', 'mere', 'aur', 'ko', 'se', 'ke', 'ki',
            'the', 'is', 'how', 'to', 'what', 'can', 'i', 'do', 'you', 'my', 'in', 'on', 'a', 'an', 'please', 'help', 'want'
        ];

        $keywords = array_values(array_filter($cleanQueryWords, fn($w) => mb_strlen($w) >= 3 && !in_array($w, $stopWords)));
        if (empty($keywords)) {
            return null;
        }

        $bestMatch = null;
        $highestScore = 0;

        foreach ($faqs as $faq) {
            $qText = mb_strtolower($faq->question);
            $aText = mb_strtolower($faq->answer);
            $catText = mb_strtolower($faq->category);

            $score = 0;
            foreach ($keywords as $kw) {
                if (mb_strpos($qText, $kw) !== false) {
                    $score += 3;
                }
                if (mb_strpos($catText, $kw) !== false) {
                    $score += 2;
                }
                if (mb_strpos($aText, $kw) !== false) {
                    $score += 1;
                }
            }

            if ($score > $highestScore && $score >= 2) {
                $highestScore = $score;
                $bestMatch = $faq;
            }
        }

        return $bestMatch;
    }
}
