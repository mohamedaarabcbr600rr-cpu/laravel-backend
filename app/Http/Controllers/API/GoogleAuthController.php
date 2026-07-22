<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request)
    {
        if ($request->has('ref')) {
            session(['referral_code' => strtoupper($request->query('ref'))]);
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request)
    {
        $frontendUrl = env('FRONTEND_URL', 'https://studmo.com');

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            return redirect($frontendUrl . '/auth/callback?error=google_failed');
        }

        $user = User::where('email', $googleUser->getEmail())->first();

        if ($user) {
            $user->last_active = now();
            if (!$user->profile_pic && $googleUser->getAvatar()) {
                $user->profile_pic = $googleUser->getAvatar();
            }
            if (!$user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }
            $user->save();
        } else {
            $referralCode = session('referral_code');
            $referrer = null;
            if ($referralCode) {
                $referrer = User::where('referral_code', $referralCode)->first();
            }

           $user = User::create([
                'name' => $googleUser->getName() ?? $googleUser->getNickname() ?? 'Utilisateur',
                'username' => $googleUser->getName() ?? ('user' . Str::random(6)),
                'email' => $googleUser->getEmail(),
                'password' => Hash::make(Str::random(32)),
                'profile_pic' => $googleUser->getAvatar(),
                'last_active' => now(),
                'referred_by' => $referrer?->id,
            ]);

            $user->markEmailAsVerified();

            // Google email is already verified by Google — credit the referrer immediately
            if ($referrer && !$user->referral_credited) {
                $referrer->increment('referral_count');
                $user->referral_credited = true;
                $user->save();
            }

            $user->profile()->create([
                'niveau' => 'debutant',
                'score_moyen' => 0,
                'total_qcm' => 0,
                'points_faibles' => json_encode([]),
                'points_forts' => json_encode([]),
            ]);
        }

        session()->forget('referral_code');

        $token = $user->createToken('talib_token')->plainTextToken;

        return redirect($frontendUrl . '/auth/callback?token=' . $token);
    }
}