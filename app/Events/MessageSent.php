<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    /**
     * Create a new event instance.
     */
    public function __construct(Message $message)
    {
        // نحمل user باش React يلقاه مباشرة
        $this->message = $message->load('user');
    }

    /**
     * القناة لي غادي يتبعت فيها event
     */
    public function broadcastOn()
    {
        return new PrivateChannel('conversation.' . $this->message->conversation_id);
    }

    /**
     * اسم event (اختياري ولكن مهم للتنظيم)
     */
    public function broadcastAs()
    {
        return 'MessageSent';
    }

    /**
     * الداتا لي غادي تمشي ل React
     */
    public function broadcastWith()
    {
        return [
            'message' => $this->message
        ];
    }
}