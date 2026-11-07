<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
        'avatar_url',
        'is_online',
        'last_active_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'is_online' => 'boolean',
        ];
    }

    /**
     * Tenant relationship.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Departments this agent belongs to.
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_user');
    }

    /**
     * Conversations assigned to this agent.
     */
    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_agent_id');
    }

    /**
     * Messages sent by this agent.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * Role checks.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isAgent(): bool
    {
        return $this->role === 'agent';
    }

    /**
     * Websites this staff member is explicitly assigned to (website-specific staff).
     */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'property_user')->withTimestamps();
    }

    /**
     * Website restriction for this user:
     *  - null  => no restriction inside their company (owners/admins, or agents with no website assignment)
     *  - array => only these property ids (agents assigned to specific websites)
     */
    public function restrictedPropertyIds(): ?array
    {
        if ($this->isSuperAdmin() || $this->isAdmin()) {
            return null;
        }

        if (! array_key_exists('restricted_property_ids', $this->runtimeCache)) {
            $ids = $this->properties()->where('properties.tenant_id', $this->tenant_id)->pluck('properties.id')->all();
            $this->runtimeCache['restricted_property_ids'] = $ids === [] ? null : $ids;
        }

        return $this->runtimeCache['restricted_property_ids'];
    }

    /** True when the user may work with data of this website. */
    public function canAccessProperty(?Property $property): bool
    {
        if (! $property) {
            return false;
        }
        if ($this->isSuperAdmin()) {
            return true;
        }
        if ($property->tenant_id !== $this->tenant_id) {
            return false;
        }
        $restricted = $this->restrictedPropertyIds();

        return $restricted === null || in_array($property->id, $restricted, true);
    }

    /**
     * May change this website's settings (widget, departments, FAQs, knowledge)?
     * Company owners/admins: every company website. Agents: only websites where they were given
     * the website-admin role (property_user.role = admin).
     */
    public function canManageProperty(?Property $property): bool
    {
        if (! $property) {
            return false;
        }
        if ($this->isSuperAdmin()) {
            return true;
        }
        if ($property->tenant_id !== $this->tenant_id) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }

        return \Illuminate\Support\Facades\DB::table('property_user')
            ->where('user_id', $this->id)->where('property_id', $property->id)->where('role', 'admin')->exists();
    }

    public function isCompanyOwner(): bool
    {
        return $this->tenant_id !== null && $this->tenant?->owner_user_id === $this->id;
    }

    /** Per-request memo (not persisted, not serialised). */
    private array $runtimeCache = [];
}
