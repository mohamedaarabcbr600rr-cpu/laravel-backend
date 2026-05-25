<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FocusTask extends Model
{
    protected $fillable = [
        'study_plan_id',
        'description',
        'duration',
        'order_index',
        'status'
    ];

    public function studyPlan(): BelongsTo
    {
        return $this->belongsTo(StudyPlan::class);
    }
}