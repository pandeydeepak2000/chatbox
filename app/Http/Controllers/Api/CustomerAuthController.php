<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\CustomerAccount;
use App\Models\Message;
use App\Models\Property;
use App\Models\Visitor;
use App\Services\Audit;
use App\Services\CustomerAuthService;
use App\Services\LeadCaptureService;
use App\Support\WidgetAccess;
use App\Support\WidgetOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Website customer Support PIN: signup (no OTP), login, forgot PIN (e-mail OTP),
 * e-mail verification, logout, and access to the customer's own history.
 * Customer identity is completely separate from staff authentication.
 */
class CustomerAuthController extends Controller
{
    private const LOGIN_FAILED = 'Email or PIN is incorrect. After several failed attempts sign-in is paused for a while — use "Forgot PIN" if you need a new one.';
    private const FORGOT_SENT = 'If an account exists for this e-mail on this website, we have sent a 6-digit code. It expires in 10 minutes.';

    public function signup(Request $request): JsonResponse
    {
        $v = $request->validate([
            'property_key' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'pin' => ['required', 'string'],
            'visitor_token' => ['nullable', 'string', 'max:100'],
        ]);
        $property = $this->property($request, $v['property_key']);
        if ($property instanceof JsonResponse) {
            return $property;
        }

        [$account, $error] = CustomerAuthService::register($property, $v['email'], $v['pin']);
        if ($error === 'exists') {
            // Never overwrite or log into an existing account. The real owner is told by e-mail.
            $existing = CustomerAuthService::findAccount($property, $v['email']);
            if ($existing) {
                CustomerAuthService::mailSafely($existing->email, 'Someone tried to register with your e-mail', implode("\n", [
                    'Hello,',
                    '',
                    "Someone tried to create a new support account on {$property->site_name} using your e-mail address.",
                    'Your existing account and its history were NOT changed.',
                    '',
                    'If it was you, choose "Continue Previous Support" in the chat window and sign in with your PIN, or use "Forgot PIN".',
                ]));
                Audit::record('customer.signup_existing_email', $existing, [], tenantId: $property->tenant_id);
            }

            return response()->json([
                'success' => false,
                'code' => 'account_exists',
                'message' => 'We could not create a new account with this e-mail. If you have used our support before, choose "Continue Previous Support" or "Forgot PIN".',
            ], 409);
        }
        if ($error) {
            return response()->json(['success' => false, 'message' => $error, 'errors' => ['pin' => [$error]]], 422);
        }

        $visitor = $this->visitor($property, $v['visitor_token'] ?? null);
        CustomerAuthService::attachDevice($account, $visitor);
        if ($visitor) {
            LeadCaptureService::capture($visitor, null, ['email' => $account->email]);
        }
        Audit::record('customer.signup', $account, [], tenantId: $property->tenant_id);

        return response()->json([
            'success' => true,
            'message' => 'Your support account is ready.',
            'data' => $this->session($account, $request),
        ], 201);
    }

    /**
     * Chat-style sign-in step: after the customer types their e-mail the widget asks either
     * "create your PIN" (new) or "enter your PIN" (existing). Rate-limited like login.
     */
    public function identify(Request $request): JsonResponse
    {
        $v = $request->validate([
            'property_key' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        $property = $this->property($request, $v['property_key']);
        if ($property instanceof JsonResponse) {
            return $property;
        }
        $account = CustomerAuthService::findAccount($property, $v['email']);

        return response()->json(['success' => true, 'data' => ['exists' => (bool) $account, 'locked' => (bool) $account?->isLocked()]]);
    }

    public function login(Request $request): JsonResponse
    {
        $v = $request->validate([
            'property_key' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'pin' => ['required', 'string', 'max:20'],
            'visitor_token' => ['nullable', 'string', 'max:100'],
        ]);
        $property = $this->property($request, $v['property_key']);
        if ($property instanceof JsonResponse) {
            return $property;
        }

        [$account, $status] = CustomerAuthService::attemptLogin($property, $v['email'], $v['pin']);
        if (! $account) {
            Audit::record('customer.login_failed', null, ['status' => $status], tenantId: $property->tenant_id);

            return response()->json(['success' => false, 'message' => self::LOGIN_FAILED], 422);
        }

        CustomerAuthService::attachDevice($account, $this->visitor($property, $v['visitor_token'] ?? null));
        Audit::record('customer.login', $account, [], tenantId: $property->tenant_id);

        return response()->json(['success' => true, 'data' => $this->session($account, $request)]);
    }

    public function forgot(Request $request): JsonResponse
    {
        $v = $request->validate([
            'property_key' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        $property = $this->property($request, $v['property_key']);
        if ($property instanceof JsonResponse) {
            return $property;
        }

        $account = CustomerAuthService::findAccount($property, $v['email']);
        if ($account) {
            $sent = CustomerAuthService::sendOtp($account, 'pin_reset');
            Audit::record('customer.pin_reset_requested', $account, ['mail_sent' => $sent], tenantId: $property->tenant_id);
            if (! $sent) {
                // Honest failure: the mail service is unavailable. (Does not reveal existence: the
                // same message is returned when sending fails for any address.)
                return response()->json(['success' => false, 'message' => 'We could not send the code right now. Please try again in a few minutes.'], 503);
            }
        }

        return response()->json(['success' => true, 'message' => self::FORGOT_SENT]);
    }

    public function reset(Request $request): JsonResponse
    {
        $v = $request->validate([
            'property_key' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'max:10'],
            'pin' => ['required', 'string'],
            'visitor_token' => ['nullable', 'string', 'max:100'],
        ]);
        $property = $this->property($request, $v['property_key']);
        if ($property instanceof JsonResponse) {
            return $property;
        }
        if ($problem = CustomerAuthService::pinProblem($v['pin'])) {
            return response()->json(['success' => false, 'message' => $problem, 'errors' => ['pin' => [$problem]]], 422);
        }

        $account = CustomerAuthService::findAccount($property, $v['email']);
        $result = $account ? CustomerAuthService::verifyOtp($account, 'pin_reset', $v['code']) : 'invalid';
        if ($result !== 'ok') {
            return response()->json([
                'success' => false,
                'message' => $result === 'expired'
                    ? 'This code has expired or was used too many times. Please request a new code.'
                    : 'The code is not correct. Please check the latest e-mail and try again.',
            ], 422);
        }

        CustomerAuthService::resetPin($account, $v['pin']);
        CustomerAuthService::attachDevice($account, $this->visitor($property, $v['visitor_token'] ?? null));
        Audit::record('customer.pin_reset', $account->fresh(), [], tenantId: $property->tenant_id);

        return response()->json([
            'success' => true,
            'message' => 'Your new PIN is set. You have been signed out everywhere else.',
            'data' => $this->session($account->fresh(), $request),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        CustomerAuthService::revokeToken($request);

        return response()->json(['success' => true]);
    }

    public function requestVerification(Request $request): JsonResponse
    {
        [$property, $account] = $this->authed($request);
        if (! $account) {
            return $this->unauthenticated();
        }
        if ($account->isVerified()) {
            return response()->json(['success' => true, 'message' => 'Your e-mail is already verified.']);
        }
        $sent = CustomerAuthService::sendOtp($account, 'email_verify');

        return $sent
            ? response()->json(['success' => true, 'message' => 'We sent a 6-digit code to your e-mail. It expires in 10 minutes.'])
            : response()->json(['success' => false, 'message' => 'We could not send the code right now. Please try again later.'], 503);
    }

    public function confirmVerification(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:10']]);
        [$property, $account] = $this->authed($request);
        if (! $account) {
            return $this->unauthenticated();
        }
        $result = CustomerAuthService::verifyOtp($account, 'email_verify', (string) $request->input('code'));
        if ($result !== 'ok') {
            return response()->json(['success' => false, 'message' => 'The code is not correct or has expired.'], 422);
        }
        if (! $account->isVerified()) {
            $account->forceFill(['email_verified_at' => now()])->save();
            Conversation::where('customer_account_id', $account->id)->update(['pre_verification' => true]);
        }
        Audit::record('customer.email_verified', $account, [], tenantId: $property->tenant_id);

        return response()->json(['success' => true, 'message' => 'Thank you, your e-mail address is verified.']);
    }

    /** The customer's own profile, conversations and tickets. */
    public function me(Request $request): JsonResponse
    {
        [$property, $account] = $this->authed($request);
        if (! $account) {
            return $this->unauthenticated();
        }

        return response()->json(['success' => true, 'data' => $this->profile($account)]);
    }

    /** Full public history of one of the customer's own conversations (any device). */
    public function conversation(Request $request, string $id): JsonResponse
    {
        [$property, $account] = $this->authed($request);
        if (! $account) {
            return $this->unauthenticated();
        }
        $conversation = WidgetAccess::conversation($request, $id, null, $property->id);
        if (! $conversation) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $messages = $conversation->messages()->visibleToCustomer()->get();

        return response()->json(['success' => true, 'data' => [
            'conversation' => $this->conversationSummary($conversation),
            'messages' => $messages,
            'last_seq' => (int) $conversation->last_seq,
        ]]);
    }

    // ------------------------------------------------------------------

    public static function conversationSummary(Conversation $c): array
    {
        $last = $c->relationLoaded('latestMessage') ? $c->latestMessage : null;

        return [
            'id' => $c->id,
            'status' => $c->status,
            'handler' => $c->handler ?? 'ai',
            'created_at' => $c->created_at?->toIso8601String(),
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'preview' => $last ? mb_substr((string) $last->content, 0, 120) : null,
        ];
    }

    private function profile(CustomerAccount $account): array
    {
        $conversations = Conversation::where('customer_account_id', $account->id)
            ->with(['latestMessage' => fn ($q) => $q->where('visibility', 'public')])
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get();

        $tickets = class_exists(\App\Models\Ticket::class)
            ? \App\Models\Ticket::where('customer_account_id', $account->id)->orderByDesc('updated_at')->limit(50)->get()
                ->map(fn ($t) => $t->toCustomerArray())->values()
            : [];

        return [
            'customer' => [
                'id' => $account->id,
                'email' => $account->email,
                'email_verified' => $account->isVerified(),
            ],
            'conversations' => $conversations->map(fn ($c) => self::conversationSummary($c))->values(),
            'tickets' => $tickets,
        ];
    }

    private function session(CustomerAccount $account, Request $request): array
    {
        return array_merge(['token' => CustomerAuthService::issueSession($account, $request)], $this->profile($account));
    }

    /** @return array{0: ?Property, 1: ?CustomerAccount} */
    private function authed(Request $request): array
    {
        $key = (string) ($request->input('property_key') ?? $request->query('property_key', ''));
        $property = $this->activeProperty($key);
        if (! $property || ! WidgetOrigin::allows($request, $property)) {
            return [null, null];
        }

        return [$property, CustomerAuthService::resolve($request, $property)];
    }

    private function property(Request $request, string $key): Property|JsonResponse
    {
        $property = $this->activeProperty($key);
        if (! $property) {
            return response()->json(['success' => false, 'message' => 'Invalid or inactive property key.'], 404);
        }
        if (! WidgetOrigin::allows($request, $property)) {
            return response()->json(['success' => false, 'message' => '403 Forbidden: Unauthorized Domain Origin.'], 403);
        }

        return $property;
    }

    private function activeProperty(string $key): ?Property
    {
        if ($key === '') {
            return null;
        }

        return Cache::remember("prop_pub_{$key}", 120, fn () => Property::where('public_key', $key)->where('is_active', true)->first());
    }

    private function visitor(Property $property, ?string $token): ?Visitor
    {
        return $token ? Visitor::where('property_id', $property->id)->where('visitor_token', $token)->first() : null;
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json(['success' => false, 'code' => 'customer_unauthenticated', 'message' => 'Please sign in with your e-mail and Support PIN.'], 401);
    }
}
