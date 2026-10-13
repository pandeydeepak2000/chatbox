<?php

use App\Models\Conversation;
use App\Models\Property;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
*/

// Agent & Visitor Chat Channel
Broadcast::channel('chat.{conversationId}', function ($user, $conversationId) {
    if (! $user) {
        return false;
    }

    $conversation = Conversation::with('property')->find($conversationId);
    if (! $conversation) {
        return false;
    }

    // Super Admin has universal access
    if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'super_admin'];
    }

    // Authenticated Agent / Tenant Admin check
    if (! empty($user->tenant_id) && $conversation->property->tenant_id === $user->tenant_id) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => $user->role];
    }

    return false;
});

// Tenant Property Inbox Presence Channel (New chats, Online agent status)
Broadcast::channel('inbox.{propertyId}', function ($user, $propertyId) {
    if (! $user) {
        return false;
    }

    if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'super_admin'];
    }

    $property = Property::find($propertyId);
    if ($property && $property->tenant_id === $user->tenant_id) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => $user->role];
    }

    return false;
});
