<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $table = 'groups';

    protected $fillable = [
        'name', 'description', 'cover_image', 'created_by',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($group) {
            if (empty($group->invite_token)) {
                do {
                    $token = \Illuminate\Support\Str::random(16);
                } while (self::where('invite_token', $token)->exists());
                $group->invite_token = $token;
            }
        });
    }

    public function users()
{
    return $this->belongsToMany(User::class, 'group_user')->withTimestamps()->withPivot('last_read_at');
}

public function messages()
{
    return $this->hasMany(GroupMessage::class, 'group_id');
}

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}