<?php

namespace App\Http\Controllers;

use App\Models\User;

class PresenceController extends Controller
{
    public function online()
    {
        auth()->user()->update([
            'is_online' => true
        ]);

        return response()->json([
            'message' => 'online'
        ]);
    }

    public function offline()
    {
        auth()->user()->update([
            'is_online' => false
        ]);

        return response()->json([
            'message' => 'offline'
        ]);
    }

    public function users()
    {
        return User::where(
            'is_online',
            true
        )->get();
    }
}