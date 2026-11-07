<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskReminder extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_id', 'remind_at', 'sent_at', 'cancelled_at'];

    protected function casts(): array
    {
        return ['remind_at' => 'datetime', 'sent_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
