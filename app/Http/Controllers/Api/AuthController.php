<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CannedResponse;
use App\Models\Department;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new Tenant account (Company Owner).
     */
    public function registerTenant(Request $request): JsonResponse
    {
        // Open self-registration has no e-mail verification yet (audit M6): disabled unless enabled in .env.
        if (! config('chatbox.public_registration')) {
            return response()->json([
                'success' => false,
                'message' => 'Self-registration is disabled. Please contact the platform administrator to create a company workspace.',
            ], 403);
        }

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email', 'unique:tenants,email'],
            'password' => ['required', 'string', 'min:8'],
            'site_name' => ['nullable', 'string', 'max:255'],
            'site_url' => ['nullable', 'string', 'max:255'],
        ]);

        $result = DB::transaction(function () use ($validated) {
            // 1. Create Tenant
            $tenant = Tenant::create([
                'name' => $validated['company_name'],
                'email' => $validated['email'],
                'plan' => 'free',
                'status' => 'active',
            ]);

            // 2. Create Owner User (Role: admin)
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'admin',
                'is_online' => true,
                'last_active_at' => now(),
            ]);

            // 3. Create initial Default Property for this tenant
            $siteName = $validated['site_name'] ?? $validated['company_name'];
            $siteUrl = $validated['site_url'] ?? 'https://example.com';
            $host = parse_url($siteUrl, PHP_URL_HOST) ?: 'localhost';

            $property = Property::create([
                'tenant_id' => $tenant->id,
                'site_name' => $siteName,
                'site_url' => $siteUrl,
                'allowed_domains' => array_unique(['localhost', '127.0.0.1', $host]),
                'widget_config' => [
                    'primary_color' => '#4f46e5',
                    'welcome_message' => 'Hello! How can we assist you today?',
                    'logo_url' => null,
                    'position' => 'right',
                    'require_email' => true,
                    'require_name' => true,
                ],
            ]);

            $tenant->forceFill(['owner_user_id' => $user->id])->save();
            \App\Http\Controllers\Api\PlatformController::createSubscription($tenant->id, (string) config('chatbox.default_plan', 'pro'));

            // 4. Create default departments
            $supportDept = Department::create([
                'property_id' => $property->id,
                'name' => 'Support',
                'description' => 'General customer & technical support',
                'is_active' => true,
            ]);

            $salesDept = Department::create([
                'property_id' => $property->id,
                'name' => 'Sales',
                'description' => 'Sales and pre-purchase inquiries',
                'is_active' => true,
            ]);

            // Attach owner to both departments
            $user->departments()->attach([$supportDept->id, $salesDept->id]);

            // 5. Create default canned response
            CannedResponse::create([
                'property_id' => $property->id,
                'shortcut' => '/greet',
                'content' => 'Hello! Welcome to ' . $siteName . '. How can I help you today?',
                'created_by' => $user->id,
            ]);

            $token = $user->createToken('auth-token')->plainTextToken;

            return [
                'user' => $user,
                'tenant' => $tenant,
                'property' => $property->toArrayFor($user),
                'token' => $token,
            ];
        });
        Audit::record('tenant.registered', $result['tenant'], [], $result['user']);

        return response()->json([
            'success' => true,
            'message' => 'Tenant registered successfully.',
            'data' => $result,
        ], 201);
    }

    /**
     * User Login (Super Admin, Tenant Admin, Agent).
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'website_slug' => ['nullable', 'string', 'max:60'],
        ]);

        // Brute-force protection: e-mail + IP (5 tries / 15 min) and, against attacks spread over
        // many IPs, per account (20 tries / hour).
        $throttleKey = Str::transliterate(Str::lower($credentials['email']) . '|' . $request->ip());
        $accountKey = 'login-acct:' . hash('sha256', Str::lower($credentials['email']));

        if (RateLimiter::tooManyAttempts($throttleKey, 5) || RateLimiter::tooManyAttempts($accountKey, 20)) {
            $seconds = max(RateLimiter::availableIn($throttleKey), RateLimiter::availableIn($accountKey));
            // Context array instead of string interpolation: no log-line injection (CR/LF in e-mail).
            Log::warning('Security alert: login lockout triggered', ['email' => Str::limit(preg_replace('/[\x00-\x1F\x7F]/', '', $credentials['email']), 120), 'ip' => $request->ip()]);
            return response()->json([
                'success' => false,
                'message' => "Too many failed login attempts. For security, your account has been temporarily locked. Please try again in {$seconds} seconds.",
            ], 429);
        }

        $user = User::with(['tenant'])->where('email', $credentials['email'])->first();
        // Always run one bcrypt check so the response time does not reveal whether the account exists.
        static $dummyHash = null;
        $dummyHash ??= Hash::make('chatbox-timing-equaliser');
        $passwordOk = Hash::check($credentials['password'], $user?->password ?? $dummyHash) && $user !== null;

        if (! $passwordOk) {
            RateLimiter::hit($throttleKey, 900); // 15-minute lockout after 5 strikes
            RateLimiter::hit($accountKey, 3600);
            Audit::record('auth.login_failed', $user, ['email' => mb_strtolower($credentials['email'])], tenantId: $user?->tenant_id);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        // Website-specific admin URL (/{slug}/admin): only staff with access to that website may sign
        // in there. The error is identical to a wrong password (no hint which part was wrong).
        if (! empty($credentials['website_slug'])) {
            $site = Property::where('slug', $credentials['website_slug'])->first();
            if (! $site || ! $user->canAccessProperty($site)) {
                RateLimiter::hit($throttleKey, 900);
                Audit::record('auth.login_failed', $user, ['website_slug' => $credentials['website_slug'], 'reason' => 'no_website_access'], tenantId: $user->tenant_id);
                throw ValidationException::withMessages([
                    'email' => ['The provided credentials do not match our records.'],
                ]);
            }
        }

        // Clear brute force strikes on successful login
        RateLimiter::clear($throttleKey);
        RateLimiter::clear($accountKey);

        // Check if tenant is active (unless super admin)
        if ($user->tenant && ! $user->tenant->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Your tenant account is currently suspended. Please contact support.',
            ], 403);
        }

        $user->update([
            'is_online' => true,
            'last_active_at' => now(),
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;
        Audit::record('auth.login', $user, [], $user);

        // Load properties belonging to tenant (or all for super_admin). Secrets only for admins (audit H5).
        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => array_merge($this->workspace($user), ['token' => $token]),
        ]);
    }

    /**
     * Get Current Authenticated User.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->workspace($request->user()),
        ]);
    }

    /**
     * The signed-in staff member, the websites they may access (with admin URLs) and their
     * permissions. Website-restricted agents only receive their own websites.
     */
    private function workspace(User $user): array
    {
        $user->load(['tenant', 'departments']);
        $properties = $user->tenant_id
            ? Property::where('tenant_id', $user->tenant_id)->with('departments')->get()
            : ($user->isSuperAdmin() ? Property::with('departments')->get() : collect());
        $properties = collect($properties)
            ->filter(fn (Property $p) => $user->canAccessProperty($p))
            ->map(fn (Property $p) => array_merge($p->toArrayFor($user), [
                'admin_url' => $p->adminUrl(),
                'can_manage' => $user->canManageProperty($p),
            ]))->values();

        return [
            'user' => $user,
            'properties' => $properties,
            'permissions' => [
                'is_super_admin' => $user->isSuperAdmin(),
                'is_company_admin' => $user->isAdmin(),
                'is_company_owner' => $user->isCompanyOwner(),
                'website_restricted' => $user->restrictedPropertyIds() !== null,
            ],
        ];
    }

    /**
     * User Logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $user->update(['is_online' => false]);
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Toggle Agent Online / Offline Status.
     */
    public function updateStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_online' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $user->update([
            'is_online' => $validated['is_online'],
            'last_active_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Online status updated.',
            'data' => [
                'is_online' => $user->is_online,
            ],
        ]);
    }

    /**
     * Create / Invite a Support Agent under current Tenant (Admin only).
     */
    public function createAgent(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Only tenant administrators can add agents.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['string'],
            'property_ids' => ['nullable', 'array'],
            'property_ids.*' => ['string'],
        ]);

        if (! $user->tenant_id) {
            return response()->json(['success' => false, 'message' => 'Platform administrators must create agents from a company workspace.'], 422);
        }
        if ($error = $this->rejectForeignDepartments($validated['department_ids'] ?? [], $user->tenant_id)) {
            return $error;
        }
        if ($error = $this->rejectForeignProperties($validated['property_ids'] ?? [], $user->tenant_id)) {
            return $error;
        }

        $agent = \App\Services\PlanLimits::withCapacity($user->tenant_id, 'staff', fn () => DB::transaction(function () use ($validated, $user) {
            $newAgent = User::create([
                'tenant_id' => $user->tenant_id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'agent',
                'is_online' => false,
            ]);

            if (! empty($validated['department_ids'])) {
                $newAgent->departments()->sync($validated['department_ids']);
            }
            if (! empty($validated['property_ids'])) {
                $newAgent->properties()->sync($validated['property_ids']);
            }
            // Explicit access mode (security F1): no websites ticked = all company websites.
            $newAgent->setWebsiteAccess($validated['property_ids'] ?? []);

            return $newAgent;
        }));
        Audit::record('agent.created', $agent, ['email' => $agent->email, 'department_ids' => $validated['department_ids'] ?? [], 'property_ids' => $validated['property_ids'] ?? []]);

        return response()->json([
            'success' => true,
            'message' => 'Agent created successfully.',
            'data' => $agent->load(['departments', 'properties:id,site_name']),
        ], 201);
    }

    /**
     * List all Agents in the current Tenant.
     */
    public function listAgents(Request $request): JsonResponse
    {
        $user = $request->user();

        $agents = User::where('tenant_id', $user->tenant_id)
            ->with(['departments', 'properties:id,site_name'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $agents,
        ]);
    }

    /**
     * Update an Agent within current Tenant (or any tenant if Super Admin).
     */
    public function updateAgent(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Only administrators can update agents.',
            ], 403);
        }

        $query = User::where('id', $id);
        if (! $user->isSuperAdmin()) {
            $query->where('tenant_id', $user->tenant_id);
        }

        $agent = $query->firstOrFail();
        if ($denied = $this->denyStaffChange($user, $agent)) {
            return $denied;
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,' . $agent->id],
            'password' => ['nullable', 'string', 'min:8'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['string'],
            'property_ids' => ['nullable', 'array'],
            'property_ids.*' => ['string'],
        ]);

        if ($error = $this->rejectForeignDepartments($validated['department_ids'] ?? [], $agent->tenant_id)) {
            return $error;
        }
        if ($error = $this->rejectForeignProperties($validated['property_ids'] ?? [], $agent->tenant_id)) {
            return $error;
        }
        // Website assignment: [] = all company websites, otherwise only the listed ones.
        $propertyIds = array_key_exists('property_ids', $validated) ? ($validated['property_ids'] ?? []) : null;
        unset($validated['property_ids']);

        $passwordChanged = ! empty($validated['password']);
        if ($passwordChanged) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $departmentIds = $validated['department_ids'] ?? null;
        unset($validated['department_ids']);
        $agent->update($validated);

        if ($departmentIds !== null) {
            $agent->departments()->sync($departmentIds);
        }
        if ($propertyIds !== null) {
            $agent->setWebsiteAccess($propertyIds);
        }
        if ($passwordChanged && $agent->id !== $user->id) {
            $agent->tokens()->delete();
        }
        Audit::record('agent.updated', $agent, ['fields' => array_keys($validated), 'password_changed' => $passwordChanged, 'property_ids' => $propertyIds]);

        return response()->json([
            'success' => true,
            'message' => 'Agent credentials and details updated successfully.',
            'data' => $agent->load(['departments', 'properties:id,site_name']),
        ]);
    }

    /**
     * Delete an Agent from Tenant.
     */
    public function deleteAgent(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Only administrators can remove agents.',
            ], 403);
        }

        $query = User::where('id', $id);
        if (! $user->isSuperAdmin()) {
            $query->where('tenant_id', $user->tenant_id)->where('id', '!=', $user->id);
        }

        $agent = $query->firstOrFail();
        if ($denied = $this->denyStaffChange($user, $agent)) {
            return $denied;
        }
        if ($agent->tenant_id && $agent->tenant?->owner_user_id === $agent->id) {
            return response()->json(['success' => false, 'message' => 'The company owner cannot be removed.'], 403);
        }
        Audit::record('agent.deleted', $agent, ['email' => $agent->email]);
        $agent->tokens()->delete();
        $agent->delete();

        return response()->json([
            'success' => true,
            'message' => 'Agent removed successfully.',
        ]);
    }

    /**
     * List all Tenants across the entire SaaS Platform (Super Admin only).
     */
    public function platformTenants(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $tenants = Tenant::with(['properties'])->withCount(['users', 'properties'])->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $tenants,
        ]);
    }

    /**
     * Platform Global Overview Stats (Super Admin only).
     */
    public function platformStats(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_tenants' => Tenant::count(),
                'active_tenants' => Tenant::where('status', 'active')->count(),
                'total_properties' => Property::count(),
                'total_agents' => User::where('role', 'agent')->count(),
                'total_conversations' => \App\Models\Conversation::count(),
                'total_messages' => \App\Models\Message::count(),
            ],
        ]);
    }

    /**
     * Step 1 of password reset: e-mail a single-use, expiring reset token (audit C1).
     * The response is identical whether or not the address exists (no account enumeration).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $status = Password::sendResetLink(['email' => mb_strtolower(trim($validated['email']))]);
        $user = User::where('email', $validated['email'])->first();
        Audit::record('auth.password_reset_requested', $user, ['status' => $status], tenantId: $user?->tenant_id);

        return response()->json([
            'success' => true,
            'message' => 'If an account exists for this e-mail address, a password reset link has been sent. The link expires in 60 minutes.',
        ]);
    }

    /**
     * Step 2 of password reset: verify the e-mailed token, set the new password and
     * revoke every existing login token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $status = Password::reset(
            ['email' => mb_strtolower(trim($validated['email'])), 'token' => $validated['token'], 'password' => $validated['password']],
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
                Audit::record('auth.password_reset', $user, [], $user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => 'This password reset link is invalid or has expired. Please request a new one.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset. Please sign in with your new password.',
        ]);
    }

    /**
     * List all Users across the platform (Super Admin only).
     */
    public function platformUsers(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $users = User::with('tenant')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Update any user's Name, Email, Password, or Role (Super Admin only).
     */
    /**
     * Security F3: company admins manage agents. Only the company owner (or a platform super admin)
     * may change or remove other administrators, and super admins are never reachable here.
     */
    private function denyStaffChange(User $actor, User $target): ?JsonResponse
    {
        if ($actor->isSuperAdmin() || $actor->id === $target->id) {
            return null;
        }
        if ($target->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }
        if ($target->isAdmin() && ! $actor->isCompanyOwner()) {
            return response()->json(['success' => false, 'message' => 'Only the company owner can change other administrators.'], 403);
        }

        return null;
    }

    public function platformUpdateUser(Request $request, string $id): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $targetUser = User::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,' . $targetUser->id],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'string', 'in:super_admin,admin,agent'],
            'tenant_id' => ['nullable', 'string', 'exists:tenants,id'],
        ]);

        $role = $validated['role'] ?? $targetUser->role;
        $tenantId = array_key_exists('tenant_id', $validated) ? $validated['tenant_id'] : $targetUser->tenant_id;
        if ($role === 'super_admin' && $tenantId !== null) {
            return response()->json(['success' => false, 'message' => 'A super admin cannot belong to a company.'], 422);
        }
        if ($role !== 'super_admin' && $tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Company staff must belong to a company.'], 422);
        }

        $passwordChanged = ! empty($validated['password']);
        if ($passwordChanged) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $before = $targetUser->only(['role', 'tenant_id', 'email']);
        $targetUser->update($validated);
        if ($passwordChanged || ($validated['role'] ?? $before['role']) !== $before['role'] || (array_key_exists('tenant_id', $validated) && $validated['tenant_id'] !== $before['tenant_id'])) {
            if ($targetUser->id !== $currentUser->id) {
                $targetUser->tokens()->delete();
            }
        }
        Audit::record('platform.user_updated', $targetUser, [
            'fields' => array_keys($validated),
            'before' => $before,
            'after' => $targetUser->only(['role', 'tenant_id', 'email']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User credentials updated successfully by Super Admin.',
            'data' => $targetUser->load('tenant'),
        ]);
    }

    /**
     * Delete any User (Super Admin only).
     */
    public function platformDeleteUser(Request $request, string $id): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if ($currentUser->id === $id) {
            return response()->json([
                'success' => false,
                'message' => 'Security Error: You cannot delete your own Super Admin master account.',
            ], 400);
        }

        $targetUser = User::findOrFail($id);
        Audit::record('platform.user_deleted', $targetUser, ['email' => $targetUser->email, 'role' => $targetUser->role]);
        $targetUser->tokens()->delete();
        $targetUser->delete();

        return response()->json([
            'success' => true,
            'message' => "User '{$targetUser->name}' deleted successfully.",
        ]);
    }

    /**
     * Flush System Cache (Super Admin only).
     */
    public function platformFlushCache(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => 'System cache flushed and cleared successfully across all tenants!',
        ]);
    }

    /**
     * Update Current User Profile (Any Authenticated User).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'string', 'min:8'],
            'current_password' => ['nullable', 'string'],
        ]);

        // Security F9: changing the sign-in e-mail or password needs the current password, so a
        // stolen session token cannot be turned into a permanent account takeover.
        $emailChanged = mb_strtolower($validated['email']) !== mb_strtolower($user->email);
        if (($emailChanged || ! empty($validated['password'])) && ! Hash::check((string) ($validated['current_password'] ?? ''), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Enter your current password to change your e-mail or password.']]);
        }
        unset($validated['current_password']);
        $oldEmail = $user->email;

        $passwordChanged = ! empty($validated['password']);
        if ($passwordChanged) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);
        if ($emailChanged) {
            Audit::record('auth.email_changed', $user, ['from' => $oldEmail]);
            try {
                \Illuminate\Support\Facades\Mail::raw("Hello,\n\nThe sign-in e-mail of your ChatBox account was changed to {$user->email}.\nIf you did not do this, contact your company administrator immediately.", function ($m) use ($oldEmail) {
                    $m->to($oldEmail)->subject('Your ChatBox sign-in e-mail was changed');
                });
            } catch (\Throwable $e) {
                Log::warning('Could not send e-mail-change notice', ['user' => $user->id]);
            }
        }
        if ($passwordChanged) {
            // Sign out every other session; keep the one making this request.
            $currentTokenId = $user->currentAccessToken()?->id;
            $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();
            Audit::record('auth.password_changed', $user);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your profile has been updated successfully!',
            'data' => $user->load('tenant'),
        ]);
    }

    /**
     * Department ids must all belong to websites of the given company (audit H3).
     */
    private function rejectForeignDepartments(array $departmentIds, ?string $tenantId): ?JsonResponse
    {
        $departmentIds = array_values(array_unique(array_filter($departmentIds)));
        if (empty($departmentIds)) {
            return null;
        }

        $valid = Department::whereIn('id', $departmentIds)
            ->whereHas('property', fn ($q) => $q->where('tenant_id', $tenantId))
            ->count();

        if ($valid !== count($departmentIds)) {
            return response()->json([
                'success' => false,
                'message' => 'One or more selected departments are invalid.',
                'errors' => ['department_ids' => ['One or more selected departments are invalid.']],
            ], 422);
        }

        return null;
    }

    /** Website ids must all belong to the given company. */
    private function rejectForeignProperties(array $propertyIds, ?string $tenantId): ?JsonResponse
    {
        $propertyIds = array_values(array_unique(array_filter($propertyIds)));
        if (empty($propertyIds)) {
            return null;
        }
        if (Property::whereIn('id', $propertyIds)->where('tenant_id', $tenantId)->count() !== count($propertyIds)) {
            return response()->json([
                'success' => false,
                'message' => 'One or more selected websites are invalid.',
                'errors' => ['property_ids' => ['One or more selected websites are invalid.']],
            ], 422);
        }

        return null;
    }

    /**
     * Super Admin creates a new Tenant company and assigns a ChatBox website property.
     */
    public function platformCreateTenant(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        if (! $currentUser->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:8'],
            'website_name' => ['required', 'string', 'max:255'],
            'website_url' => ['required', 'string'],
            'plan' => ['nullable', 'string', 'in:free,pro,enterprise'],
        ]);

        $result = DB::transaction(function () use ($validated, $currentUser) {
            $tenant = Tenant::create([
                'name' => $validated['company_name'],
                'email' => $validated['admin_email'],
                'plan' => $validated['plan'] ?? 'pro',
                'status' => 'active',
                'settings' => ['timezone' => 'UTC', 'language' => 'en'],
            ]);

            $adminUser = User::create([
                'tenant_id' => $tenant->id,
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => Hash::make($validated['admin_password']),
                'role' => 'admin',
                'is_online' => false,
            ]);
            $tenant->forceFill(['owner_user_id' => $adminUser->id])->save();
            \App\Http\Controllers\Api\PlatformController::createSubscription($tenant->id, $validated['plan'] ?? 'pro');

            // Whitelist only the company's own website by default, not every domain (audit H1).
            $host = parse_url($validated['website_url'], PHP_URL_HOST) ?: $validated['website_url'];
            $property = Property::create([
                'tenant_id' => $tenant->id,
                'site_name' => $validated['website_name'],
                'site_url' => $validated['website_url'],
                'allowed_domains' => [strtolower((string) $host)],
                'widget_config' => [
                    'primary_color' => '#2563eb',
                    'welcome_message' => 'Hello! Welcome to ' . $validated['company_name'] . '. How can we help you?',
                    'position' => 'right',
                    'require_email' => true,
                    'require_name' => true,
                ],
                'is_active' => true,
            ]);

            Department::create([
                'property_id' => $property->id,
                'name' => 'General Support',
                'description' => 'General inquiries',
                'is_active' => true,
            ]);

            return [
                'tenant' => $tenant,
                'admin' => $adminUser,
                'property' => $property->toArrayFor($currentUser),
            ];
        });
        Audit::record('platform.tenant_created', $result['tenant'], ['company' => $validated['company_name']]);

        return response()->json([
            'success' => true,
            'message' => 'Company ChatBox workspace created successfully!',
            'data' => $result,
        ], 201);
    }
}
