<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'experience_id',
        'reaction_type'
    ];

    protected $attributes = [
        'reaction_type' => 'like',
    ];

    // ✅ Types de réactions
    const REACTION_LIKE = 'like';
    const REACTION_LOVE = 'love';
    const REACTION_HAHA = 'haha';
    const REACTION_WOW = 'wow';
    const REACTION_SAD = 'sad';
    const REACTION_ANGRY = 'angry';

    public static function reactionTypes()
    {
        return [
            self::REACTION_LIKE => 'like',
            self::REACTION_LOVE => 'love',
            self::REACTION_HAHA => 'haha',
            self::REACTION_WOW => 'wow',
            self::REACTION_SAD => 'sad',
            self::REACTION_ANGRY => 'angry',
        ];
    }

    // ✅ Emoji correspondant
    public function getEmojiAttribute()
    {
        return match($this->reaction_type) {
            self::REACTION_LOVE => '❤️',
            self::REACTION_HAHA => '😂',
            self::REACTION_WOW => '😮',
            self::REACTION_SAD => '😢',
            self::REACTION_ANGRY => '😡',
            default => '👍',
        };
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function experience()
    {
        return $this->belongsTo(Experience::class);
    }
}