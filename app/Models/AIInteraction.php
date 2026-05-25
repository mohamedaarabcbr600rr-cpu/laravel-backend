<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// app/Models/AIInteraction.php


class AIInteraction extends Model
{
    protected $fillable = [
        'user_id', 'type', 'input_text', 'ai_response', 'metadata'
    ];
    
    protected $casts = [
        'metadata' => 'array'
    ];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}