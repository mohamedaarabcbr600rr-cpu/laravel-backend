<?php

namespace App\Events;

use App\Models\GroupMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts a new message to all members of a group.
 *
 * Channel:  private-group.{id}
 * Event:    .group-message.sent
 *
 * Frontend listener: channel.listen('.group-message.sent', cb)
 */
class GroupMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public GroupMessage $message) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('group.' . $this->message->group_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'group-message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id'                       => $this->message->id,
            'group_id'                 => $this->message->group_id,
            'user_id'                  => $this->message->user_id,
            'content'                  => $this->message->content,
            'file_path'                => $this->message->file_path,
            'file_type'                => $this->message->file_type,
            'reply_to_id'              => $this->message->reply_to_id,
            'deleted_for_everyone_at'  => $this->message->deleted_for_everyone_at?->toIso8601String(),
            'created_at'               => $this->message->created_at?->toIso8601String(),
            'user'                     => $this->message->user ? [
                'id'   => $this->message->user->id,
                'name' => $this->message->user->name,
                'profile_pic' => $this->message->user->profile_pic,
            ] : null,
            'reply_to'                 => $this->message->replyTo ? [
                'id' => $this->message->replyTo->id,
                'content' => $this->message->replyTo->content,
                'deleted_for_everyone_at' => $this->message->replyTo->deleted_for_everyone_at?->toIso8601String(),
                'user' => $this->message->replyTo->user ? [
                    'name' => $this->message->replyTo->user->name,
                ] : null,
            ] : null,
        ];
    }
}