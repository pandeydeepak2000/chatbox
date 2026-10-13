<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;
    public string $propertyId;

    /**
     * Create a new event instance.
     */
    public function __construct(User $user, string $propertyId)
    {
        $this->user = $user;
        $this->propertyId = $propertyId;
    }

    /**
     * The channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('inbox.' . $this->propertyId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'agent.status.changed';
    }

    /**
     * Data to broadcast with the event.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'agent_id' => $this->user->id,
            'name' => $this->user->name,
            'is_online' => $this->user->is_online,
            'last_active_at' => $this->user->last_active_at?->toISOString(),
        ];
    }
}
