<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts a new message to all participants of a conversation.
 *
 * Channel:  private-conversation.{id}
 * Event:    .message.sent
 *
 * Frontend listener: channel.listen('.message.sent', cb)
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.' . $this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id'              => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'user_id'         => $this->message->user_id,
            'content'         => $this->message->content,
            'file_path'       => $this->message->file_path,
            'file_type'       => $this->message->file_type,
            'seen'            => (bool) $this->message->seen,
            'created_at'      => $this->message->created_at?->toIso8601String(),
            'user'            => $this->message->user ? [
                'id'   => $this->message->user->id,
                'name' => $this->message->user->name,
            ] : null,
        ];
    }
}
