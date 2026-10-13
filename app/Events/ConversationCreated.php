<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Conversation $conversation;

    /**
     * Create a new event instance.
     */
    public function __construct(Conversation $conversation)
    {
        $this->conversation = $conversation;
    }

    /**
     * The channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('inbox.' . $this->conversation->property_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'conversation.created';
    }

    /**
     * Data to broadcast with the event.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->conversation->id,
            'property_id' => $this->conversation->property_id,
            'status' => $this->conversation->status,
            'visitor' => [
                'id' => $this->conversation->visitor->id,
                'name' => $this->conversation->visitor->name,
                'email' => $this->conversation->visitor->email,
                'city' => $this->conversation->visitor->city,
                'country_code' => $this->conversation->visitor->country_code,
                'browser' => $this->conversation->visitor->browser,
                'os' => $this->conversation->visitor->os,
                'current_page_url' => $this->conversation->visitor->current_page_url,
            ],
            'department' => $this->conversation->department ? [
                'id' => $this->conversation->department->id,
                'name' => $this->conversation->department->name,
            ] : null,
            'last_message_at' => $this->conversation->last_message_at?->toISOString() ?? now()->toISOString(),
            'created_at' => $this->conversation->created_at->toISOString(),
        ];
    }
}
