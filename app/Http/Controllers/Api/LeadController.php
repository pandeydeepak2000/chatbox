<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\Property;
use App\Models\User;
use App\Services\Audit;
use App\Services\LeadExporter;
use App\Support\SafeText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company Leads Management. Every query goes through Lead::visibleTo(), so company and
 * website permissions apply identically to the list, detail, updates and exports.
 */
class LeadController extends Controller
{
    private const DISPLAY_TZ = 'Asia/Kolkata';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $this->validatedFilters($request);
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));

        $query = $this->filteredQuery($user, $filters)
            ->with(['property:id,site_name', 'assignee:id,name'])
            ->withCount('conversations');

        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (Lead $l) => $this->row($l))->values(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /** Options for the filter bar: only websites and staff this user may see. */
    public function meta(Request $request): JsonResponse
    {
        $user = $request->user();
        $properties = Property::query()
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->when($user->restrictedPropertyIds() !== null, fn ($q) => $q->whereIn('id', $user->restrictedPropertyIds()))
            ->orderBy('site_name')
            ->get(['id', 'site_name']);

        $staff = User::query()
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->where('role', '!=', 'super_admin')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => [
                'statuses' => collect(Lead::STATUSES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
                'services' => collect(Lead::SERVICES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
                'websites' => $properties,
                'staff' => $staff,
                'can_delete' => $user->isAdmin() || $user->isSuperAdmin(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $lead = $this->findVisible($request->user(), $id);
        $lead->load([
            'property:id,site_name,site_url',
            'assignee:id,name',
            'notes.author:id,name',
            'conversations' => fn ($q) => $q->orderByDesc('last_message_at')->select('conversations.id', 'conversations.status', 'conversations.created_at', 'conversations.last_message_at'),
        ]);

        return response()->json([
            'success' => true,
            'data' => array_merge($this->row($lead), [
                'notes' => $lead->notes->map(fn (LeadNote $n) => [
                    'id' => $n->id,
                    'body' => $n->body,
                    'author' => $n->author?->name ?? 'System',
                    'created_at' => $n->created_at?->toIso8601String(),
                ])->values(),
                'conversations' => $lead->conversations->map(fn ($c) => [
                    'id' => $c->id,
                    'status' => $c->status,
                    'created_at' => $c->created_at?->toIso8601String(),
                    'last_message_at' => $c->last_message_at?->toIso8601String(),
                ])->values(),
                // Tickets link to conversations once the ticket module exists (next phase).
                'tickets' => [],
            ]),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $lead = $this->findVisible($user, $id);

        $validated = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'interested_service' => ['sometimes', 'nullable', Rule::in(array_keys(Lead::SERVICES))],
            'inquiry_summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(array_keys(Lead::STATUSES))],
            'assigned_to' => ['sometimes', 'nullable', 'string'],
        ]);

        if (array_key_exists('assigned_to', $validated) && $validated['assigned_to'] !== null) {
            $assignee = User::where('id', $validated['assigned_to'])->where('tenant_id', $lead->tenant_id)->first();
            if (! $assignee || ! $assignee->canAccessProperty($lead->property)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected staff member cannot access this website.',
                    'errors' => ['assigned_to' => ['The selected staff member cannot access this website.']],
                ], 422);
            }
        }

        foreach (['name', 'phone', 'website'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = SafeText::field($validated[$field], $field === 'phone' ? 50 : 255);
            }
        }
        if (array_key_exists('inquiry_summary', $validated) && $validated['inquiry_summary'] !== null) {
            $validated['inquiry_summary'] = SafeText::message($validated['inquiry_summary']);
        }
        if (array_key_exists('email', $validated) && $validated['email'] !== null) {
            $validated['email'] = mb_strtolower(trim($validated['email']));
        }

        $before = $lead->only(array_keys($validated));
        $lead->fill($validated);
        $changed = array_keys($lead->getDirty());
        $lead->save();

        if ($changed) {
            Audit::record('lead.updated', $lead, [
                'fields' => $changed,
                'status' => isset($before['status']) && in_array('status', $changed, true) ? ['from' => $before['status'], 'to' => $lead->status] : null,
                'assigned_to' => in_array('assigned_to', $changed, true) ? ['from' => $before['assigned_to'] ?? null, 'to' => $lead->assigned_to] : null,
            ]);
        }

        $lead->load(['property:id,site_name', 'assignee:id,name'])->loadCount('conversations');

        return response()->json(['success' => true, 'message' => 'Lead updated.', 'data' => $this->row($lead)]);
    }

    public function addNote(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $lead = $this->findVisible($user, $id);

        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $body = SafeText::message($validated['body']);
        if ($body === '') {
            return response()->json(['success' => false, 'message' => 'Note cannot be empty.'], 422);
        }

        $note = LeadNote::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);
        Audit::record('lead.note_added', $lead, ['note_id' => $note->id]);

        return response()->json([
            'success' => true,
            'data' => ['id' => $note->id, 'body' => $note->body, 'author' => $user->name, 'created_at' => $note->created_at?->toIso8601String()],
        ], 201);
    }

    /** Deletes the lead record only. Conversations and visitor history are kept. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $lead = $this->findVisible($request->user(), $id);
        Audit::record('lead.deleted', $lead, ['reference' => $lead->reference, 'email' => $lead->email]);
        $lead->delete();

        return response()->json(['success' => true, 'message' => 'Lead deleted. Related chats were kept.']);
    }

    /**
     * Download leads as CSV or XLSX. `scope=filtered` (default) applies the same filters as the
     * list; `scope=all` exports every lead the user may see. Always company/website scoped.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $format = $request->query('format', 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        $scope = $request->query('scope', 'filtered') === 'all' ? 'all' : 'filtered';
        $filters = $scope === 'all' ? [] : $this->validatedFilters($request);

        $query = $this->filteredQuery($user, $filters)->with(['property:id,site_name', 'assignee:id,name'])->withCount('conversations');
        $count = (clone $query)->count();

        Audit::record('leads.exported', null, [
            'format' => $format,
            'scope' => $scope,
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'rows' => $count,
        ]);

        return LeadExporter::download($query, $format, self::DISPLAY_TZ);
    }

    // ------------------------------------------------------------------

    private function findVisible(User $user, string $id): Lead
    {
        return Lead::visibleTo($user)->where('leads.id', $id)->firstOrFail();
    }

    private function validatedFilters(Request $request): array
    {
        $v = $request->validate([
            'property_id' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(Lead::STATUSES))],
            'service' => ['nullable', Rule::in(array_keys(Lead::SERVICES))],
            'assigned_to' => ['nullable', 'string', 'max:64'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'last_interaction'])],
        ]);

        return array_merge(array_fill_keys(['property_id', 'search', 'status', 'service', 'assigned_to', 'date_from', 'date_to', 'sort'], null), $v);
    }

    private function filteredQuery(User $user, array $f): Builder
    {
        $query = Lead::query()->visibleTo($user)->select('leads.*');

        if (! empty($f['property_id'])) {
            $query->where('leads.property_id', $f['property_id']);
        }
        if (! empty($f['status'])) {
            $query->where('leads.status', $f['status']);
        }
        if (! empty($f['service'])) {
            $query->where('leads.interested_service', $f['service']);
        }
        if (! empty($f['assigned_to'])) {
            match ($f['assigned_to']) {
                'me' => $query->where('leads.assigned_to', $user->id),
                'unassigned' => $query->whereNull('leads.assigned_to'),
                default => $query->where('leads.assigned_to', $f['assigned_to']),
            };
        }
        if (! empty($f['search'])) {
            $term = '%' . addcslashes(trim($f['search']), '%_\\') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('leads.name', 'like', $term)
                    ->orWhere('leads.email', 'like', $term)
                    ->orWhere('leads.phone', 'like', $term);
            });
        }
        // Dates are entered in IST and compared in UTC.
        if (! empty($f['date_from'])) {
            $query->where('leads.created_at', '>=', CarbonImmutable::parse($f['date_from'], self::DISPLAY_TZ)->startOfDay()->utc());
        }
        if (! empty($f['date_to'])) {
            $query->where('leads.created_at', '<=', CarbonImmutable::parse($f['date_to'], self::DISPLAY_TZ)->endOfDay()->utc());
        }

        match ($f['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('leads.created_at')->orderBy('leads.lead_number'),
            'last_interaction' => $query->orderByDesc('leads.last_interaction_at')->orderByDesc('leads.lead_number'),
            default => $query->orderByDesc('leads.created_at')->orderByDesc('leads.lead_number'),
        };

        return $query;
    }

    private function row(Lead $l): array
    {
        return [
            'id' => $l->id,
            'reference' => $l->reference,
            'name' => $l->name,
            'email' => $l->email,
            'phone' => $l->phone,
            'website' => $l->website,
            'property' => $l->property ? ['id' => $l->property->id, 'site_name' => $l->property->site_name] : null,
            'interested_service' => $l->interested_service,
            'interested_service_label' => $l->interested_service ? (Lead::SERVICES[$l->interested_service] ?? $l->interested_service) : null,
            'inquiry_summary' => $l->inquiry_summary,
            'status' => $l->status,
            'status_label' => Lead::STATUSES[$l->status] ?? $l->status,
            'assigned_to' => $l->assignee ? ['id' => $l->assignee->id, 'name' => $l->assignee->name] : null,
            'source' => $l->source,
            'conversations_count' => (int) ($l->conversations_count ?? 0),
            'created_at' => $l->created_at?->toIso8601String(),
            'last_interaction_at' => $l->last_interaction_at?->toIso8601String(),
        ];
    }
}
