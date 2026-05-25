<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function stats()
    {
        return [
            'total_users' => User::count(),
            'new_users_today' => User::whereDate('created_at', today())->count(),
            'dau' => User::where('last_active', '>=', now()->subDay())->count(),
            'mau' => User::where('last_active', '>=', now()->subMonth())->count(),
        ];
    }

    public function activeUsersToday()
    {
        $users = User::where(function($query) {
                $query->where('last_active', '>=', now()->startOfDay())
                      ->orWhereNull('last_active');
            })
            ->select('id', 'name', 'email', 'username', 'last_active', 'country', 'profile_pic', 'bio', 'is_online')
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'last_activity' => $user->last_active ?? now(),
                    'country' => $user->country,
                    'avatar' => $user->profile_pic,
                    'bio' => $user->bio,
                    'is_online' => $user->is_online ?? false,
                    'initials' => $this->getInitials($user->name)
                ];
            });

        return response()->json($users);
    }

    public function detailedStats()
    {
        $startOfDay = now()->startOfDay();
        $startOfWeek = now()->startOfWeek();

        return response()->json([
            'total_users' => User::count(),
            'new_users_today' => User::whereDate('created_at', today())->count(),
            'new_users_this_week' => User::where('created_at', '>=', $startOfWeek)->count(),
            'dau' => User::where('last_active', '>=', now()->subDay())->count(),
            'mau' => User::where('last_active', '>=', now()->subMonth())->count(),
            'active_today' => User::where('last_active', '>=', $startOfDay)->count(),
            'online_now' => User::where('is_online', 1)->count(),
            'users_by_country' => User::select('country', DB::raw('count(*) as count'))
                ->whereNotNull('country')
                ->groupBy('country')
                ->get(),
        ]);
    }

    public function adminLogin(Request $request)
    {
        $admin = User::where('email', $request->email)
                    ->where('is_admin', 1)
                    ->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        // Mettre à jour last_active
        $admin->last_active = now();
        $admin->save();

        $token = $admin->createToken('admin-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $admin
        ]);
    }

    private function getInitials($name)
    {
        if (!$name) return '?';
        $words = explode(' ', $name);
        $initials = '';
        foreach ($words as $word) {
            if (strlen($word) > 0) {
                $initials .= strtoupper($word[0]);
            }
        }
        return substr($initials, 0, 2);
    }
}