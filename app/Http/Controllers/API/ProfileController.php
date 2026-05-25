<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * Récupérer le profil de l'utilisateur connecté
     */
    public function show()
    {
        $user = Auth::user();
        
        return response()->json([
            'success' => true,
            'user' => $user
        ]);
    }
    
    /**
     * Mettre à jour le profil de l'utilisateur
     */
    public function update(Request $request)
    {
        $user = Auth::user();
        
        // Validation des données
        $validated = $request->validate([
            'username' => [
                'required',
                'string',
                'max:50',
                Rule::unique('users')->ignore($user->id),
                'regex:/^[a-zA-Z0-9_.]+$/'
            ],
            'name' => 'required|string|max:100',
            'bio' => 'nullable|string|max:150',
            'link' => 'nullable|url|max:255',
            'profile_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120' // 5MB max
        ]);
        
        // Mettre à jour les champs
        $user->username = $validated['username'];
        $user->name = $validated['name'];
        $user->bio = $validated['bio'] ?? null;
        $user->link = $validated['link'] ?? null;
        
        // Gérer l'upload de la photo de profil
        if ($request->hasFile('profile_pic')) {
            // Supprimer l'ancienne photo si elle existe
            if ($user->profile_pic && Storage::disk('public')->exists($user->profile_pic)) {
                Storage::disk('public')->delete($user->profile_pic);
            }
            
            // Stocker la nouvelle photo
            $path = $request->file('profile_pic')->store('profile-pictures', 'public');
            $user->profile_pic = '/storage/' . $path;
        }
        
        $user->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Profil mis à jour avec succès',
            'user' => $user
        ]);
    }
    
    /**
     * Récupérer les statistiques du profil
     */
    public function stats($userId = null)
    {
        $user = $userId ? User::findOrFail($userId) : Auth::user();
        
        $stats = [
            'posts' => $user->experiences()->count(),
            'followers' => $user->followers()->count(),
            'following' => $user->following()->count(),
            'likes' => $user->experiences()->withCount('likes')->get()->sum('likes_count')
        ];
        
        return response()->json([
            'success' => true,
            'stats' => $stats
        ]);
    }
}