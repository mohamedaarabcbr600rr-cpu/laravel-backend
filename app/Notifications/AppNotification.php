<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class AppNotification extends Notification
{
    use Queueable;

    protected $data;

    public function __construct(array $data)
    {
        // Structure standard pour TOUTES les notifications
        $this->data = array_merge([
            'type' => 'info',
            'actor_id' => null,
            'actor_name' => 'Utilisateur',
            'actor_avatar' => null,
            'message' => '',
            'experience_id' => null,
            'comment_id' => null,
            'profile_id' => null,
            'post_title' => null,
        ], $data);
    }

    public function via($notifiable)
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable)
    {
        return $this->data;
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'id' => $this->id,
            'data' => $this->data,
            'created_at' => now()->toIso8601String(),
            'read_at' => null,
        ]);
    }

    public function broadcastType()
    {
        return 'notification.new';
    }
}