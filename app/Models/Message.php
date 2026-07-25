<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'user_id',
        'content',
        'file_path',
        'file_type',
        'seen',
        'reply_to_id',
        'deleted_for_everyone_at',
    ];

    protected $casts = [
        'seen' => 'boolean',
        'deleted_for_everyone_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo()
    {
        return $this->belongsTo(Message::class, 'reply_to_id')->with('user');
    }
}