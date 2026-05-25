<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Story extends Model
{
    protected $fillable = [
        'user_id',
        'story_url',
        'type',
        'expires_at',
    ];
    
    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];
    
    /**
     * Get the user that owns the story
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    /**
     * Get the views for the story
     */
    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class);
    }
    
    /**
     * Check if story is still active (less than 24h old)
     */
    public function isActive(): bool
    {
        return $this->created_at >= now()->subHours(24);
    }
}