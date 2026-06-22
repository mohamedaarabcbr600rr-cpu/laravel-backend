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
        'shared_from'
    ];

    protected $appends = [
        'likes_count',
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

    public function medias()
    {
        return $this->hasMany(ExperienceMedia::class);
    }

    public function getLikesCountAttribute()
    {
        return $this->likes()->count();
    }

    public function getReactionsCountAttribute()
    {
        return [
            'like'  => $this->likes()->where('reaction_type', 'like')->count(),
            'love'  => $this->likes()->where('reaction_type', 'love')->count(),
            'haha'  => $this->likes()->where('reaction_type', 'haha')->count(),
            'wow'   => $this->likes()->where('reaction_type', 'wow')->count(),
            'sad'   => $this->likes()->where('reaction_type', 'sad')->count(),
            'angry' => $this->likes()->where('reaction_type', 'angry')->count(),
        ];
    }
}