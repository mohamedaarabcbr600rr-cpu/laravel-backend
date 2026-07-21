<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{

// ✅ Ajouter ici en premier
    public function index()
    {
        $currentUser = Auth::user();
        $users = User::when($currentUser, fn($q) => $q->where('id', '!=', $currentUser->id))
            ->orderBy('name')
            ->get();
        return response()->json($users);
    }

    // ✅ GET /api/users/{id} — Récupérer un utilisateur par ID
    public function show($id)
    {
        $user = User::with(['stories'])->find($id);

        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé'], 404);
        }

        return response()->json($user);
    }

    // ✅ GET /api/users/username/{username} — Récupérer par username
    public function showByUsername($username)
    {
        $user = User::with(['stories'])
            ->where('username', $username)
            ->orWhere('name', $username)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé'], 404);
        }

        return response()->json($user);
    }

    // ✅ GET /api/users/suggestions — Suggestions d'utilisateurs à suivre
    public function suggestions(Request $request)
    {
        $currentUser = Auth::user();

        $query = User::query();

        if ($currentUser) {
            // Exclure l'utilisateur connecté et ceux qu'il suit déjà
            $followingIds = $currentUser->following()->pluck('users.id')->toArray();
            $excludeIds = array_merge([$currentUser->id], $followingIds);
            $query->whereNotIn('id', $excludeIds);
        }

        $suggestions = $query->inRandomOrder()->limit(10)->get(['id', 'name', 'username', 'bio', 'profile_pic', 'referral_count']);

        return response()->json($suggestions);
    }

    // ✅ GET /api/users/{id}/experiences — Expériences d'un utilisateur
   public function experiences($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé'], 404);
        }

$experiences = $user->experiences()
            ->with([
                'user:id,name,profile_pic,referral_count',
                'likes',
                'comments' => function ($query) {
                    $query->whereNull('parent_id')
                          ->with(['user:id,name,profile_pic,referral_count', 'likes', 'replies']);
                },
                'original.user:id,name,profile_pic',
                'original.medias',
                'medias'
            ])
            ->withCount('likes')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($experiences);
    }

    // ✅ GET /api/users/{id}/stories — Stories d'un utilisateur
    public function stories($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé'], 404);
        }

        $stories = $user->stories()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($story) {
                $story->story_url = $story->story_url
                    ? asset('storage/' . ltrim($story->story_url, '/storage/'))
                    : null;
                return $story;
            });

        return response()->json($stories);
    }



    
    // ✅ GET /api/users/{id}/followers — Followers d'un utilisateur
    public function followers($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé'], 404);
        }

        $followers = $user->followers()->get();

        return response()->json($followers);
    }

    // ✅ GET /api/users/{id}/following — Following d'un utilisateur
    public function following($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé'], 404);
        }

        $following = $user->following()->get();

        return response()->json($following);
    }

    // ✅ POST /api/users/{id}/follow — Suivre / Ne plus suivre
    public function follow($id)
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        if ($currentUser->id == $id) {
            return response()->json(['message' => 'Vous ne pouvez pas vous suivre vous-même'], 400);
        }

        $targetUser = User::find($id);

        if (!$targetUser) {
            return response()->json(['message' => 'Utilisateur non trouvé'], 404);
        }

        // Toggle follow
        if ($currentUser->following()->where('following_id', $id)->exists()) {
            $currentUser->following()->detach($id);
            return response()->json(['message' => 'Unfollowed', 'following' => false]);
        } else {
            $currentUser->following()->attach($id);
            return response()->json(['message' => 'Followed', 'following' => true]);
        }
    }

    // ✅ DELETE /api/users/{id}/follow — Unfollow explicite
    public function unfollow($id)
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $currentUser->following()->detach($id);

        return response()->json(['message' => 'Unfollowed', 'following' => false]);
    }
}