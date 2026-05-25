<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{
    protected $fillable = [
        'user_id', 'niveau', 'points_faibles', 'points_forts', 
        'score_moyen', 'total_qcm', 'total_questions_repondues', 
        'bonnes_reponses', 'matieres_preferees'
    ];
    
    protected $casts = [
        'points_faibles' => 'array',
        'points_forts' => 'array',
        'matieres_preferees' => 'array',
        'score_moyen' => 'float'
    ];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function interactions()
    {
        return $this->hasMany(AIInteraction::class, 'user_id', 'user_id');
    }
    
    public function progress()
    {
        return $this->hasMany(StudentProgress::class, 'user_id', 'user_id');
    }
    
    public function updateNiveauFromScore()
    {
        if ($this->score_moyen < 50) {
            $this->niveau = 'debutant';
        } elseif ($this->score_moyen < 75) {
            $this->niveau = 'intermediaire';
        } else {
            $this->niveau = 'avance';
        }
        
        return $this;
    }
}