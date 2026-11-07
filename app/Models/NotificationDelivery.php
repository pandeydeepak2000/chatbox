<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $fillable = ['tenant_id', 'channel_id', 'event', 'status', 'attempts', 'message', 'last_error', 'next_attempt_at', 'sent_at'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'attempts' => 'integer'];
    }
}
