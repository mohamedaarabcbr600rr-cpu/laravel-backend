<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;


    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
  protected $fillable = [
    'name', 'username', 'email', 'password', 'bio', 'link', 'profile_pic', 'referred_by', 'country', 'last_active', 'referral_code'
];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

protected static function boot()
{
    parent::boot();

    static::creating(function ($user) {
        if (empty($user->referral_code)) {
            do {
                $code = strtoupper(\Illuminate\Support\Str::random(8));
            } while (self::where('referral_code', $code)->exists());
            $user->referral_code = $code;
        }
    });
}


    public function experiences()
{
    return $this->hasMany(Experience::class);
}






    /**
     * المستخدمين اللي هذا المستخدم يتابعهم
     */
    public function followings()
    {
        return $this->belongsToMany(
            User::class,           // الموديل
            'follows',             // اسم الجدول
            'follower_id',         // اللي يتابع
            'following_id'         // اللي تتم متابعته
        )->withTimestamps();
    }
    
    /**
     * المستخدمين اللي يتابعون هذا المستخدم
     */
    public function followers()
    {
        return $this->belongsToMany(
            User::class,
            'follows',
            'following_id',        // اللي تتم متابعته
            'follower_id'          // اللي يتابع
        )->withTimestamps();
    }
    
    /**
     * هل هذا المستخدم يتابع مستخدم آخر؟
     */
    public function isFollowing(User $user)
    {
        return $this->followings()->where('following_id', $user->id)->exists();
    }
    
    /**
     * متابعة مستخدم
     */
    public function follow(User $user)
    {
        // منع متابعة النفس
        if ($this->id === $user->id) {
            return false;
        }
        
        return $this->followings()->syncWithoutDetaching([$user->id]);
    }
    
    /**
     * إلغاء متابعة مستخدم
     */
    public function unfollow(User $user)
    {
        return $this->followings()->detach($user->id);
    }
    
    /**
     * عكس حالة المتابعة (إذا كاينة نحيه، إذا ماكاينش زيدها)
     */
    public function toggleFollow(User $user)
    {
        if ($this->isFollowing($user)) {
            $this->unfollow($user);
            return false; // معناتها راه دار Unfollow
        } else {
            $this->follow($user);
            return true; // معناتها راه دار Follow
        }
    }

 // les messaggee prives 
    public function connections()
{
    return User::whereIn('id', function ($query) {
        $query->select('following_id')
              ->from('follows')
              ->where('follower_id', $this->id);
    })->whereIn('id', function ($query) {
        $query->select('follower_id')
              ->from('follows')
              ->where('following_id', $this->id);
    });
}


// les relation avec les autre table de ai 

public function profile()
{
    return $this->hasOne(StudentProfile::class);
}

public function progress()
{
    return $this->hasMany(StudentProgress::class);
}

public function aiInteractions()
{
    return $this->hasMany(AIInteraction::class);
}
public function qcmHistories()
{
    return $this->hasMany(\App\Models\QCMHistory::class);
}


// Dans App\Models\User.php

public function stories()
{
    return $this->hasMany(Story::class)->where('created_at', '>=', now()->subHours(24));
}

public function storyViews()
{
    return $this->hasMany(StoryView::class);
}


public function notifications()
{
    return $this->morphMany(
        \Illuminate\Notifications\DatabaseNotification::class,
        'notifiable'
    )->orderBy('created_at', 'desc');
}

public function sendPasswordResetNotification($token)
{
    $this->notify(new \App\Notifications\ResetPasswordNotification($token));
}
}



