<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\CustomerAccount;
use App\Models\CustomerOtp;
use App\Models\CustomerSession;
use App\Models\Property;
use App\Models\Visitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Customer Support PIN identity (Phase 2 §3).
 *
 *  - PINs and OTPs are hashed (never stored, logged or returned in plain text)
 *  - per-account lockout: 5 failures => 15 min, then 1 h, then 24 h (escalating)
 *  - generic responses for login and forgot-PIN (no account enumeration)
 *  - session tokens are random 40-byte values stored as SHA-256 hashes, 30-day sliding expiry,
 *    revocable, bound to one website; a PIN reset revokes every session
 *  - signup never overwrites or merges an existing account
 */
class CustomerAuthService
{
    public const SESSION_DAYS = 30;
    public const LOCK_STEPS_MINUTES = [15, 60, 1440];
    private const TRIVIAL_PINS = ['000000', '111111', '222222', '333333', '444444', '555555', '666666', '777777', '888888', '999999', '123456', '654321', '123123', '112233', '121212', '012345', '098765'];

    public static function pinProblem(string $pin): ?string
    {
        if (! preg_match('/^\d{6}$/', $pin)) {
            return 'Your PIN must be exactly 6 digits (numbers only).';
        }
        if (in_array($pin, self::TRIVIAL_PINS, true)) {
            return 'This PIN is too easy to guess. Please choose a different 6-digit PIN.';
        }

        return null;
    }

    public static function findAccount(Property $property, string $email): ?CustomerAccount
    {
        return CustomerAccount::where('property_id', $property->id)
            ->where('email_normalized', CustomerAccount::normalizeEmail($email))
            ->first();
    }

    /** @return array{0: ?CustomerAccount, 1: ?string} [account, error] */
    public static function register(Property $property, string $email, string $pin): array
    {
        if ($problem = self::pinProblem($pin)) {
            return [null, $problem];
        }

        try {
            $account = CustomerAccount::create([
                'tenant_id' => $property->tenant_id,
                'property_id' => $property->id,
                'email' => trim($email),
                'email_normalized' => CustomerAccount::normalizeEmail($email),
                'pin_hash' => Hash::make($pin),
                'pin_changed_at' => now(),
                'session_version' => 1,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return [null, 'exists']; // unique (property_id, email_normalized)
        }

        return [$account, null];
    }

    /**
     * @return array{0: ?CustomerAccount, 1: string} [account|null, status] status: ok|invalid|locked
     */
    public static function attemptLogin(Property $property, string $email, string $pin): array
    {
        $account = self::findAccount($property, $email);

        if (! $account) {
            static $dummy = null;
            $dummy ??= Hash::make('not-a-real-pin');
            Hash::check($pin, $dummy); // equalise timing with the "wrong PIN" path

            return [null, 'invalid'];
        }

        // Security (logic F8): the attempt is counted BEFORE the PIN is checked, under a row lock,
        // so parallel guesses cannot exceed 5 per lock window.
        if (! self::reserveAttempt($account)) {
            return [null, 'locked'];
        }

        if (! Hash::check($pin, $account->pin_hash)) {
            self::registerFailure($account);

            return [null, $account->fresh()->isLocked() ? 'locked' : 'invalid'];
        }

        $account->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();

        return [$account, 'ok'];
    }

    /** Count one attempt (in flight or failed). False when the account is locked or the limit is reached. */
    private static function reserveAttempt(CustomerAccount $account): bool
    {
        return DB::transaction(function () use ($account) {
            $a = CustomerAccount::whereKey($account->id)->lockForUpdate()->first();
            if ($a->isLocked()) {
                return false;
            }
            if ($a->failed_attempts >= 5) {
                self::applyLock($a);

                return false;
            }
            $a->failed_attempts++;
            $a->save();

            return true;
        });
    }

    /** After a wrong PIN: lock the account once five attempts have been used. */
    private static function registerFailure(CustomerAccount $account): void
    {
        DB::transaction(function () use ($account) {
            $a = CustomerAccount::whereKey($account->id)->lockForUpdate()->first();
            if ($a->failed_attempts >= 5 && ! $a->isLocked()) {
                self::applyLock($a);
            }
        });
    }

    /** Lock for 15 min, then 1 h, then 24 h; tell the owner by e-mail. Caller holds the row lock. */
    private static function applyLock(CustomerAccount $a): void
    {
        $minutes = self::LOCK_STEPS_MINUTES[min($a->lockout_level, count(self::LOCK_STEPS_MINUTES) - 1)];
        $a->locked_until = now()->addMinutes($minutes);
        $a->lockout_level = min($a->lockout_level + 1, 10);
        $a->failed_attempts = 0;
        $a->save();
        Audit::record('customer.locked', $a, ['minutes' => $minutes], tenantId: $a->tenant_id);
        self::mailSafely($a->email, 'Security notice: several failed PIN attempts', implode("\n", [
            'Hello,',
            '',
            "Several incorrect Support PIN attempts were made for your support account on {$a->property?->site_name}.",
            "For your security the account is locked for {$minutes} minutes.",
            '',
            'If this was not you, use "Forgot PIN" in the chat window to set a new PIN.',
        ]));
    }

    /** Issue a session token. Only the hash is stored; the plain token is returned once. */
    public static function issueSession(CustomerAccount $account, Request $request): string
    {
        $token = 'cst_' . Str::random(48);
        CustomerSession::create([
            'customer_account_id' => $account->id,
            'property_id' => $account->property_id,
            'token_hash' => hash('sha256', $token),
            'session_version' => (int) ($account->session_version ?? 1),
            'expires_at' => now()->addDays(self::SESSION_DAYS),
            'last_used_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return $token;
    }

    /** Resolve the customer from the X-Customer-Token header for this website. */
    public static function resolve(Request $request, Property $property): ?CustomerAccount
    {
        $token = (string) ($request->header('X-Customer-Token') ?? '');
        if ($token === '' || ! str_starts_with($token, 'cst_')) {
            return null;
        }

        $session = CustomerSession::with('account')
            ->where('token_hash', hash('sha256', $token))
            ->where('property_id', $property->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $session || ! $session->account || $session->session_version !== $session->account->session_version) {
            return null;
        }
        // Absolute limit (security C-F7): even an active session must sign in again after 90 days,
        // so a token copied from a customer's browser cannot be used forever.
        if ($session->created_at && $session->created_at->lt(now()->subDays(90))) {
            return null;
        }

        // Sliding expiry, written at most once per hour to keep polling cheap on shared hosting.
        if (! $session->last_used_at || $session->last_used_at->lt(now()->subHour())) {
            $session->forceFill(['last_used_at' => now(), 'expires_at' => now()->addDays(self::SESSION_DAYS)])->save();
        }

        return $session->account;
    }

    public static function revokeToken(Request $request): void
    {
        $token = (string) $request->header('X-Customer-Token', '');
        if ($token !== '') {
            CustomerSession::where('token_hash', hash('sha256', $token))->update(['revoked_at' => now()]);
        }
    }

    public static function revokeAll(CustomerAccount $account): void
    {
        $account->forceFill(['session_version' => $account->session_version + 1])->save();
        CustomerSession::where('customer_account_id', $account->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** Create and e-mail a 6-digit OTP (code + instructions only, no links). Returns false if mail failed. */
    public static function sendOtp(CustomerAccount $account, string $purpose): bool
    {
        CustomerOtp::where('customer_account_id', $account->id)->where('purpose', $purpose)->whereNull('consumed_at')
            ->update(['consumed_at' => now()]); // only the newest code is valid

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        CustomerOtp::create([
            'customer_account_id' => $account->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(CustomerOtp::TTL_MINUTES),
        ]);

        $site = $account->property?->site_name ?? 'our website';
        $what = $purpose === 'pin_reset' ? 'reset your Support PIN' : 'verify your e-mail address';

        return self::mailSafely($account->email, "Your {$site} support code", implode("\n", [
            'Hello,',
            '',
            "Use this code to {$what} in the {$site} chat window:",
            '',
            "    {$code}",
            '',
            'The code expires in ' . CustomerOtp::TTL_MINUTES . ' minutes and can be used only once.',
            'Type it into the chat window where you requested it. We will never ask you for this code by phone or e-mail.',
            '',
            'If you did not request this, you can ignore this e-mail; your account has not been changed.',
        ]));
    }

    /** @return string ok|invalid|expired */
    public static function verifyOtp(CustomerAccount $account, string $purpose, string $code): string
    {
        return DB::transaction(function () use ($account, $purpose, $code) {
            $otp = CustomerOtp::where('customer_account_id', $account->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->latest()
                ->lockForUpdate()
                ->first();

            if (! $otp || ! $otp->isUsable()) {
                return 'expired';
            }

            if (! preg_match('/^\d{6}$/', $code) || ! Hash::check($code, $otp->code_hash)) {
                $otp->increment('attempts');

                return $otp->attempts >= CustomerOtp::MAX_ATTEMPTS ? 'expired' : 'invalid';
            }

            $otp->forceFill(['consumed_at' => now()])->save();

            return 'ok';
        });
    }

    /**
     * New PIN after a successful OTP. The OTP proves e-mail ownership, so the account becomes verified.
     * If this is the FIRST proof of ownership, earlier conversations are flagged as created before
     * verification (possible ownership dispute, Phase 2 §3.6) — they are never merged or deleted.
     */
    public static function resetPin(CustomerAccount $account, string $pin): void
    {
        DB::transaction(function () use ($account, $pin) {
            $firstVerification = $account->email_verified_at === null;
            $account->forceFill([
                'pin_hash' => Hash::make($pin),
                'pin_changed_at' => now(),
                'email_verified_at' => $account->email_verified_at ?? now(),
                'failed_attempts' => 0,
                'locked_until' => null,
            ])->save();
            self::revokeAll($account);
            if ($firstVerification) {
                Conversation::where('customer_account_id', $account->id)->update(['pre_verification' => true]);
            }
        });
    }

    /**
     * Attach the guest conversation(s) of THIS device to the account. Device possession is the
     * only proof used; nothing is matched by e-mail or name.
     */
    public static function attachDevice(CustomerAccount $account, ?Visitor $visitor): void
    {
        if (! $visitor || $visitor->property_id !== $account->property_id) {
            return;
        }
        if ($visitor->customer_account_id && $visitor->customer_account_id !== $account->id) {
            return; // device already belongs to another customer; never move it
        }
        // Staff should see who they are talking to: fill the visitor's e-mail if it is empty (never overwrite).
        $visitor->forceFill(array_filter([
            'customer_account_id' => $account->id,
            'email' => $visitor->email ? null : $account->email,
        ]))->save();
        Conversation::where('visitor_id', $visitor->id)->whereNull('customer_account_id')
            ->update(['customer_account_id' => $account->id]);
    }

    public static function mailSafely(string $to, string $subject, string $body): bool
    {
        try {
            Mail::raw($body, function ($m) use ($to, $subject) {
                $m->to($to)->subject(str_replace(["\r", "\n"], ' ', $subject));
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('Customer e-mail could not be sent: ' . $e->getMessage());

            return false;
        }
    }
}
