<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TypingEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $conversationId;
    public string $senderType;
    public ?string $senderName;
    public bool $isTyping;

    /**
     * Create a new event instance.
     */
    public function __construct(string $conversationId, string $senderType, ?string $senderName, bool $isTyping = true)
    {
        $this->conversationId = $conversationId;
        $this->senderType = $senderType;
        $this->senderName = $senderName;
        $this->isTyping = $isTyping;
    }

    /**
     * The channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.' . $this->conversationId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return $this->senderType === 'visitor' ? 'client-visitor-typing' : 'client-agent-typing';
    }

    /**
     * Data to broadcast with the event.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'sender_type' => $this->senderType,
            'sender_name' => $this->senderName,
            'is_typing' => $this->isTyping,
        ];
    }
}
