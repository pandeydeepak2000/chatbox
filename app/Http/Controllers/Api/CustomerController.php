<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\CustomerAccount;
use App\Models\Property;
use App\Models\Ticket;
use App\Services\Audit;
use App\Services\CustomerAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Staff view of website customers (Support PIN accounts).
 * A customer who forgot their PIN can use "Forgot PIN" (e-mailed code) themselves; when that is
 * not possible, a website administrator can set a new PIN here. Staff never see the PIN itself.
 */
class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'property_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:locked,unverified,verified'],
        ]);

        $q = CustomerAccount::query()->with('property:id,tenant_id,site_name')
            ->where('tenant_id', $user->tenant_id);
        if (($ids = $user->restrictedPropertyIds()) !== null) {
            $q->whereIn('property_id', $ids);
        }
        if (! empty($v['property_id'])) {
            $q->where('property_id', $v['property_id']);
        }
        if (! empty($v['search'])) {
            $q->where('email_normalized', 'like', '%' . addcslashes(mb_strtolower($v['search']), '%_\\') . '%');
        }
        match ($v['status'] ?? null) {
            'locked' => $q->where('locked_until', '>', now()),
            'unverified' => $q->whereNull('email_verified_at'),
            'verified' => $q->whereNotNull('email_verified_at'),
            default => null,
        };

        $page = $q->latest()->paginate(25);
        $ids = $page->getCollection()->pluck('id');
        $tickets = Ticket::whereIn('customer_account_id', $ids)->selectRaw('customer_account_id, count(*) as n')->groupBy('customer_account_id')->pluck('n', 'customer_account_id');
        $chats = Conversation::whereIn('customer_account_id', $ids)->selectRaw('customer_account_id, count(*) as n')->groupBy('customer_account_id')->pluck('n', 'customer_account_id');

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn (CustomerAccount $a) => $this->row($a, $request, (int) ($tickets[$a->id] ?? 0), (int) ($chats[$a->id] ?? 0)))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Administrator sets a new 6-digit PIN for a customer who cannot use the e-mailed code. */
    public function resetPin(Request $request, string $id): JsonResponse
    {
        $account = $this->findManageable($request, $id);
        $v = $request->validate(['pin' => ['required', 'string']]);
        if ($problem = CustomerAuthService::pinProblem($v['pin'])) {
            throw ValidationException::withMessages(['pin' => [$problem]]);
        }

        $account->forceFill([
            'pin_hash' => Hash::make($v['pin']),
            'pin_changed_at' => now(),
            'failed_attempts' => 0,
            'locked_until' => null,
        ])->save();
        CustomerAuthService::revokeAll($account); // every device must sign in again with the new PIN

        $site = $account->property?->site_name ?? 'our website';
        CustomerAuthService::mailSafely($account->email, 'Your Support PIN was changed', implode("\n", [
            'Hello,',
            '',
            "A member of the {$site} support team set a new Support PIN for your account, as requested.",
            'You have been signed out on all devices. Use your e-mail and the new PIN to continue.',
            '',
            'If you did not ask for this, reply to this e-mail or contact support straight away.',
        ]));
        Audit::record('customer.pin_reset_by_staff', $account, [], $request->user(), $account->tenant_id);

        return response()->json(['success' => true, 'message' => 'New PIN saved. The customer was signed out everywhere and notified by e-mail.']);
    }

    /** Remove a temporary lock after too many wrong PINs. */
    public function unlock(Request $request, string $id): JsonResponse
    {
        $account = $this->findManageable($request, $id);
        $account->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'lockout_level' => 0])->save();
        Audit::record('customer.unlocked_by_staff', $account, [], $request->user(), $account->tenant_id);

        return response()->json(['success' => true, 'message' => 'Account unlocked.']);
    }

    private function findManageable(Request $request, string $id): CustomerAccount
    {
        $user = $request->user();
        $account = CustomerAccount::where('tenant_id', $user->tenant_id)->findOrFail($id);
        $property = Property::find($account->property_id);
        if (! $user->canManageProperty($property)) {
            abort(403, 'Only administrators of this website can change customer PINs.');
        }

        return $account;
    }

    private function row(CustomerAccount $a, Request $request, int $tickets, int $chats): array
    {
        return [
            'id' => $a->id,
            'email' => $a->email,
            'property' => $a->property ? ['id' => $a->property->id, 'site_name' => $a->property->site_name] : null,
            'email_verified' => $a->email_verified_at !== null,
            'locked' => $a->isLocked(),
            'locked_until' => $a->locked_until,
            'last_login_at' => $a->last_login_at,
            'pin_changed_at' => $a->pin_changed_at,
            'created_at' => $a->created_at,
            'tickets' => $tickets,
            'conversations' => $chats,
            'can_manage' => $request->user()->canManageProperty($a->property),
        ];
    }
}
