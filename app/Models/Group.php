<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $table = 'groups';

    protected $fillable = [
        'name', 'description', 'cover_image', 'created_by',
    ];

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