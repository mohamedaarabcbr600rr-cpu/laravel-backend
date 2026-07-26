<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupMessage extends Model
{
    protected $table = 'group_messages';

    protected $fillable = [
        'group_id', 'user_id', 'content', 'file_path', 'file_type',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}