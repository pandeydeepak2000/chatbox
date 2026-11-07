<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Audit;
use App\Services\BusinessHours;
use App\Services\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Company settings, website settings (admin URL slug, identity mode, working hours),
 * website staff roles, overview and analytics.
 */
class WorkspaceController extends Controller
{
    /** Public: branding for the website-specific admin login page (/{slug}/admin). */
    public function publicWebsite(string $slug): JsonResponse
    {
        $p = Property::where('slug', $slug)->where('is_active', true)->first(['id', 'site_name', 'slug', 'widget_config']);
        abort_unless($p, 404);

        return response()->json(['success' => true, 'data' => [
            'site_name' => $p->site_name,
            'slug' => $p->slug,
            'primary_color' => $p->widget_config['primary_color'] ?? '#2563eb',
        ]]);
    }

    public function company(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->tenant_id, 404);
        $tenant = $user->tenant;
        $settings = $tenant->settings ?? [];

        return response()->json(['success' => true, 'data' => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'email' => $tenant->email,
            'timezone' => $tenant->timezone,
            'ticket_prefix' => $tenant->ticket_prefix,
            'owner_user_id' => $tenant->owner_user_id,
            'working_hours' => array_merge(BusinessHours::DEFAULT, $settings['working_hours'] ?? []),
            'sales_followup_user_id' => $settings['sales_followup_user_id'] ?? null,
            'subscription' => PlanLimits::limits($tenant->id),
            'usage' => PlanLimits::usage($tenant->id),
            'can_edit' => $user->isAdmin(),
        ]]);
    }

    public function saveCompany(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->tenant_id && $user->isAdmin(), 403);
        $tenant = $user->tenant;
        $v = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'ticket_prefix' => ['required', 'string', 'regex:/^[A-Z]{2,6}$/'],
            'working_hours.start' => ['required', 'date_format:H:i'],
            'working_hours.end' => ['required', 'date_format:H:i'],
            'working_hours.days' => ['required', 'array', 'min:1'],
            'working_hours.days.*' => ['integer', 'between:1,7'],
            'sales_followup_user_id' => ['nullable', 'string'],
        ], ['ticket_prefix.regex' => 'Use 2–6 capital letters, e.g. AMB.']);

        if (! empty($v['sales_followup_user_id']) && ! User::where('id', $v['sales_followup_user_id'])->where('tenant_id', $tenant->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Invalid staff member.', 'errors' => ['sales_followup_user_id' => ['Invalid staff member.']]], 422);
        }

        $settings = $tenant->settings ?? [];
        $settings['working_hours'] = [
            'timezone' => $v['timezone'],
            'start' => $v['working_hours']['start'],
            'end' => $v['working_hours']['end'],
            'days' => array_values(array_unique(array_map('intval', $v['working_hours']['days']))),
        ];
        $settings['sales_followup_user_id'] = $v['sales_followup_user_id'] ?? null;
        $tenant->forceFill(['name' => $v['name'], 'timezone' => $v['timezone'], 'ticket_prefix' => $v['ticket_prefix'], 'settings' => $settings])->save();
        Audit::record('company.updated', $tenant, ['fields' => array_keys($v)]);

        return $this->company($request);
    }

    /** Website admin settings that are not part of the widget customizer. */
    public function saveWebsite(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $property = Property::findOrFail($id);
        abort_unless($user->canManageProperty($property), 403, 'You cannot manage this website.');

        $v = $request->validate([
            'slug' => ['sometimes', 'string', 'max:40'],
            'identity_mode' => ['sometimes', Rule::in(['guest_only', 'pin_optional', 'pin_required'])],
            'site_name' => ['sometimes', 'string', 'max:255'],
            'site_url' => ['sometimes', 'url', 'max:255'],
            'allowed_domains' => ['sometimes', 'array', 'max:20'],
            'allowed_domains.*' => ['string', 'max:255', 'regex:/^(\*|[A-Za-z0-9.-]+)$/'],
            'is_active' => ['sometimes', 'boolean'],
            'brand_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'operating_hours' => ['sometimes', 'array'],
            'operating_hours.enabled' => ['required_with:operating_hours', 'boolean'],
            'operating_hours.start' => ['required_with:operating_hours', 'date_format:H:i'],
            'operating_hours.end' => ['required_with:operating_hours', 'date_format:H:i'],
            'operating_hours.timezone' => ['required_with:operating_hours', 'timezone:all'],
            'operating_hours.offline_notice' => ['nullable', 'string', 'max:500'],
        ]);
        if (isset($v['slug'])) {
            $slug = strtolower(trim($v['slug']));
            if ($problem = Property::slugProblem($slug, $property->id)) {
                return response()->json(['success' => false, 'message' => $problem, 'errors' => ['slug' => [$problem]]], 422);
            }
            $property->slug = $slug;
        }
        if (isset($v['is_active']) && ! $user->isAdmin()) {
            abort(403, 'Only company administrators can enable or disable websites.');
        }
        foreach (['identity_mode', 'site_name', 'site_url', 'is_active'] as $f) {
            if (array_key_exists($f, $v)) {
                $property->{$f} = $v[$f];
            }
        }
        if (isset($v['allowed_domains'])) {
            $property->allowed_domains = array_values(array_unique(array_map(fn ($d) => strtolower(trim($d)), $v['allowed_domains'])));
        }
        $config = $property->widget_config ?? [];
        if (array_key_exists('brand_name', $v)) {
            $config['brand_name'] = $v['brand_name'];
        }
        if (isset($v['operating_hours'])) {
            $config['operating_hours'] = $v['operating_hours'];
        }
        $property->widget_config = $config;
        $changed = array_keys($property->getDirty());
        $property->save();
        Cache::forget("prop_pub_{$property->public_key}");
        Audit::record('property.updated', $property, ['fields' => $changed]);

        return response()->json(['success' => true, 'data' => array_merge($property->toArrayFor($user), ['admin_url' => $property->adminUrl()])]);
    }

    /** Staff who can work on a website, with their website role. */
    public function websiteStaff(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $property = Property::findOrFail($id);
        abort_unless($user->canManageProperty($property), 403);
        $assigned = DB::table('property_user')->where('property_id', $property->id)->pluck('role', 'user_id');
        $staff = User::where('tenant_id', $property->tenant_id)->orderBy('name')->get(['id', 'name', 'email', 'role']);

        return response()->json(['success' => true, 'data' => $staff->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'company_role' => $u->role,
            'website_role' => $u->isAdmin() ? 'company_admin' : ($assigned[$u->id] ?? null),
            'access' => $u->isAdmin() || isset($assigned[$u->id]) || $u->restrictedPropertyIds() === null,
            'restricted' => ! $u->isAdmin() && $u->restrictedPropertyIds() !== null,
        ])->values()]);
    }

    /** Set a staff member's role on one website: null (remove), agent, admin. */
    public function setWebsiteStaff(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $property = Property::findOrFail($id);
        abort_unless($user->tenant_id === $property->tenant_id && $user->isAdmin(), 403, 'Only company administrators can change website staff.');
        $v = $request->validate(['user_id' => ['required', 'string'], 'role' => ['nullable', Rule::in(['agent', 'admin'])]]);
        $member = User::where('id', $v['user_id'])->where('tenant_id', $property->tenant_id)->firstOrFail();
        if ($member->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Company administrators already have access to every website.'], 422);
        }
        if ($v['role']) {
            DB::table('property_user')->updateOrInsert(['property_id' => $property->id, 'user_id' => $member->id], ['role' => $v['role'], 'updated_at' => now(), 'created_at' => now()]);
        } else {
            DB::table('property_user')->where('property_id', $property->id)->where('user_id', $member->id)->delete();
        }
        Audit::record('property.staff_changed', $property, ['user_id' => $member->id, 'role' => $v['role']]);

        return $this->websiteStaff($request, $id);
    }

    /** Overview + analytics for the dashboard (company/website scoped). */
    public function analytics(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365'], 'property_id' => ['nullable', 'string', 'max:64']]);
        $days = (int) ($v['days'] ?? 30);
        $since = now()->subDays($days)->startOfDay();

        $propertyIds = Property::query()
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->when($user->restrictedPropertyIds() !== null, fn ($q) => $q->whereIn('id', $user->restrictedPropertyIds()))
            ->when(! empty($v['property_id']), fn ($q) => $q->where('id', $v['property_id']))
            ->pluck('id');

        $conv = Conversation::whereIn('property_id', $propertyIds)->where('created_at', '>=', $since);
        $tickets = Ticket::whereIn('property_id', $propertyIds);
        $leads = Lead::whereIn('property_id', $propertyIds);

        $firstResponse = (clone $conv)->whereNotNull('first_human_response_at')->get(['created_at', 'first_human_response_at'])
            ->map(fn ($c) => $c->created_at->diffInSeconds($c->first_human_response_at));
        $botMessages = DB::table('messages')->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereIn('conversations.property_id', $propertyIds)->where('messages.created_at', '>=', $since)->where('messages.sender_type', 'bot');

        $series = (clone $conv)->selectRaw('DATE(created_at) as day, COUNT(*) as n')->groupBy('day')->orderBy('day')->pluck('n', 'day');
        $perWebsite = Property::whereIn('id', $propertyIds)->get(['id', 'site_name'])->map(fn ($p) => [
            'site_name' => $p->site_name,
            'conversations' => Conversation::where('property_id', $p->id)->where('created_at', '>=', $since)->count(),
            'open_tickets' => Ticket::where('property_id', $p->id)->whereIn('status', Ticket::UNRESOLVED)->count(),
            'leads' => Lead::where('property_id', $p->id)->where('created_at', '>=', $since)->count(),
        ]);

        return response()->json(['success' => true, 'data' => [
            'days' => $days,
            'conversations' => (clone $conv)->count(),
            'conversations_open' => Conversation::whereIn('property_id', $propertyIds)->whereIn('status', ['unassigned', 'open', 'pending'])->count(),
            'human_handled' => (clone $conv)->where('handler', 'human')->count(),
            'avg_first_response_seconds' => $firstResponse->count() ? (int) round($firstResponse->avg()) : null,
            'tickets_open' => (clone $tickets)->whereIn('status', Ticket::UNRESOLVED)->count(),
            'tickets_created' => (clone $tickets)->where('created_at', '>=', $since)->count(),
            'tickets_resolved' => (clone $tickets)->where('resolved_at', '>=', $since)->count(),
            'tickets_by_source' => (clone $tickets)->where('created_at', '>=', $since)->selectRaw('source, COUNT(*) as n')->groupBy('source')->pluck('n', 'source'),
            'leads_new' => (clone $leads)->where('created_at', '>=', $since)->count(),
            'leads_by_status' => (clone $leads)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'bot_answers' => [
                'faq' => (clone $botMessages)->where('messages.metadata->source', 'faq')->count(),
                'ai' => (clone $botMessages)->where('messages.metadata->source', 'ai')->count(),
                'escalations' => (clone $botMessages)->whereIn('messages.metadata->source', ['ticket', 'handover'])->count(),
            ],
            'ai_cost_usd' => $user->tenant_id ? round((float) AiUsageLog::where('tenant_id', $user->tenant_id)->where('created_at', '>=', $since)->sum('cost_usd'), 4) : null,
            'csat_avg' => round((float) (clone $conv)->whereNotNull('rating')->avg('rating'), 2) ?: null,
            'series' => $series,
            'per_website' => $perWebsite,
        ]]);
    }
}
