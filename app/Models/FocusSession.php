<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FocusSession extends Model
{
    protected $fillable = [
        'user_id',
        'study_material_id',
        'started_at',
        'ended_at',
        'focus_score',
        'weak_points'
    ];

    protected $casts = [
        'weak_points' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function studyMaterial(): BelongsTo
    {
        return $this->belongsTo(StudyMaterial::class);
    }
}