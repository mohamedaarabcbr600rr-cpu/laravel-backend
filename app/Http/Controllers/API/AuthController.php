<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
  public function register(Request $request)
{
    $request->validate([
        'name' => 'required',
        'email' => 'required|email|unique:users',
        'password' => 'required|min:6',
        'country' => 'nullable|string'
    ]);

    $user = User::create([
        'name' => $request->name,
        'username' => $request->name, // ← ajout
        'email' => $request->email,
        'password' => Hash::make($request->password),
        'country' => $request->country,
        'last_active' => now(),
    ]);

    $user->profile()->create([
        'niveau' => 'debutant',
        'score_moyen' => 0,
        'total_qcm' => 0,
        'points_faibles' => json_encode([]),
        'points_forts' => json_encode([]),
    ]);

    $user->sendEmailVerificationNotification();

    $token = $user->createToken('talib_token')->plainTextToken;

    return response()->json([
        'user' => $user,
        'token' => $token
    ]);
}

    public function login(Request $request)
{
    if (!Auth::attempt($request->only('email', 'password'))) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    $user = Auth::user();
    
    // 👇 AJOUTEZ CES 2 LIGNES POUR METTRE À JOUR last_active
    $user->last_active = now();
    $user->save();
    
    $token = $user->createToken('talib_token')->plainTextToken;

    return response()->json([
        'user' => $user,
        'token' => $token
    ]);
}

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
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

    $token = $admin->createToken('admin-token')->plainTextToken;

    return response()->json([
        'token' => $token,
        'user' => $admin
    ]);
}
}
