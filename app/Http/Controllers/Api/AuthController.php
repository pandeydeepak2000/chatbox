<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CannedResponse;
use App\Models\Department;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
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
                'property' => $property,
                'token' => $token,
            ];
        });

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
        ]);

        // Dual-key brute force protection (Email + IP combination)
        $throttleKey = Str::transliterate(Str::lower($credentials['email']) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning("Security Alert: Brute force login lockout triggered for {$credentials['email']} from IP: {$request->ip()}");
            return response()->json([
                'success' => false,
                'message' => "Too many failed login attempts. For security, your account has been temporarily locked. Please try again in {$seconds} seconds.",
            ], 429);
        }

        $user = User::with(['tenant'])->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 900); // 15-minute lockout after 5 strikes
            $remaining = RateLimiter::remaining($throttleKey, 5);

            throw ValidationException::withMessages([
                'email' => ["The provided credentials do not match our records. ({$remaining} attempts remaining before temporary account lockout)"],
            ]);
        }

        // Clear brute force strikes on successful login
        RateLimiter::clear($throttleKey);

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

        // Load properties belonging to tenant (or all for super_admin)
        $properties = $user->tenant_id 
            ? Property::where('tenant_id', $user->tenant_id)->get() 
            : ($user->role === 'super_admin' ? Property::all() : []);

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'user' => $user,
                'properties' => $properties,
                'token' => $token,
            ],
        ]);
    }

    /**
     * Get Current Authenticated User.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['tenant', 'departments']);
        
        $properties = $user->tenant_id 
            ? Property::where('tenant_id', $user->tenant_id)->with('departments')->get()
            : ($user->role === 'super_admin' ? Property::with('departments')->get() : []);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'properties' => $properties,
            ],
        ]);
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
            'department_ids.*' => ['string', 'exists:departments,id'],
        ]);

        $agent = DB::transaction(function () use ($validated, $user) {
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

            return $newAgent;
        });

        return response()->json([
            'success' => true,
            'message' => 'Agent created successfully.',
            'data' => $agent->load('departments'),
        ], 201);
    }

    /**
     * List all Agents in the current Tenant.
     */
    public function listAgents(Request $request): JsonResponse
    {
        $user = $request->user();

        $agents = User::where('tenant_id', $user->tenant_id)
            ->with('departments')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $agents,
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
     * Password Reset (Forgot Password).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'new_password' => ['required', 'string', 'min:6'],
        ]);

        $user = User::where('email', $validated['email'])->first();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'No user account found with this email address.',
            ], 404);
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        // Revoke all existing session tokens on password change
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully! You can now log in with your new password.',
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
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['sometimes', 'string', 'in:super_admin,admin,agent'],
            'tenant_id' => ['nullable', 'string', 'exists:tenants,id'],
        ]);

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $targetUser->update($validated);

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
            'password' => ['nullable', 'string', 'min:6'],
        ]);

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Your profile has been updated successfully!',
            'data' => $user->load('tenant'),
        ]);
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
            'admin_password' => ['required', 'string', 'min:6'],
            'website_name' => ['required', 'string', 'max:255'],
            'website_url' => ['required', 'string'],
            'plan' => ['nullable', 'string', 'in:free,pro,enterprise'],
        ]);

        $result = DB::transaction(function () use ($validated) {
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

            $slug = Str::slug($validated['company_name']);
            $property = Property::create([
                'tenant_id' => $tenant->id,
                'site_name' => $validated['website_name'],
                'site_url' => $validated['website_url'],
                'public_key' => 'pk_live_' . $slug . '_' . Str::random(16),
                'secret_key' => 'sk_live_' . $slug . '_' . Str::random(24),
                'allowed_domains' => ['*'],
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
                'property' => $property,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Company ChatBox workspace created successfully!',
            'data' => $result,
        ], 201);
    }
}
