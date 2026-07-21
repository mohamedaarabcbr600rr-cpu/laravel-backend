<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $fillable = ['user_id', 'experience_id', 'parent_id', 'content'];

    // Relation avec Experience
    public function experience() {
        return $this->belongsTo(Experience::class);
    }

    // Relation avec User
    public function user() {
        return $this->belongsTo(User::class);
    }
    // Dans Comment.php
public function likes()
{
    return $this->hasMany(CommentLike::class);
}

public function replies()
{
    return $this->hasMany(Comment::class, 'parent_id')->with('user:id,name,profile_pic,referral_count', 'likes');
}

public function parent()
{
    return $this->belongsTo(Comment::class, 'parent_id');
}
}
