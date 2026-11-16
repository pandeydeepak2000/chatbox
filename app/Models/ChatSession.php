<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A live chat that has been opened by a visitor (exists before e-mail/PIN and before the first question). */
class ChatSession extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'property_id', 'visitor_id', 'department_id', 'customer_account_id', 'conversation_id', 'reference', 'started_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
