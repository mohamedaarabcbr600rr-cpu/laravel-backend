<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Experience extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'content',
        'media_path',
        'media_type',
        'shared_from'
    ];

    // 👇 IMPORTANT
    protected $appends = [
        'likes_count',
        'media_url',
        'reactions_count'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function likes()
    {
        return $this->hasMany(Like::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class)->orderBy('created_at', 'desc');
    }

    public function shares()
    {
        return $this->hasMany(Share::class);
    }

    public function original()
    {
        return $this->belongsTo(Experience::class, 'shared_from');
    }

    public function sharedPosts()
    {
        return $this->hasMany(Experience::class, 'shared_from');
    }

    // ✅ TOTAL LIKES
    public function getLikesCountAttribute()
    {
        return $this->likes()->count();
    }

    // ✅ MEDIA URL
    public function getMediaUrlAttribute()
    {
        return $this->media_path
            ? asset('storage/' . $this->media_path)
            : null;
    }

    // 🔥 REACTIONS GROUPÉES (IMPORTANT)
    public function getReactionsCountAttribute()
    {
        return [
            'like' => $this->likes()->where('reaction_type', 'like')->count(),
            'love' => $this->likes()->where('reaction_type', 'love')->count(),
            'haha' => $this->likes()->where('reaction_type', 'haha')->count(),
            'wow'  => $this->likes()->where('reaction_type', 'wow')->count(),
            'sad'  => $this->likes()->where('reaction_type', 'sad')->count(),
            'angry'=> $this->likes()->where('reaction_type', 'angry')->count(),
        ];
    }
}