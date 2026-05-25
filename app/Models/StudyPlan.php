<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyPlan extends Model
{
    protected $fillable = [
        'study_material_id',
        'plan_data'
    ];

    protected $casts = [
        'plan_data' => 'array'
    ];

    public function studyMaterial(): BelongsTo
    {
        return $this->belongsTo(StudyMaterial::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(FocusTask::class);
    }
}