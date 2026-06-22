<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExperienceMedia extends Model
{
    protected $fillable = ['experience_id', 'path', 'type'];

    protected $appends = ['url'];

    public function experience()
    {
        return $this->belongsTo(Experience::class);
    }

    public function getUrlAttribute()
    {
        return asset('storage/' . $this->path);
    }
}