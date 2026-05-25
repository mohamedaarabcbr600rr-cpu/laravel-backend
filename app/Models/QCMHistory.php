<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QCMHistory extends Model
{
    protected $table = 'qcm_histories'; // ✅ مهم

    protected $fillable = [
        'user_id',
        'score',
        'total_questions'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}