<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Experience;
use App\Models\Share;
use App\Notifications\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShareController extends Controller
{
    /**
     * Partager une publication
     */
    public function share(Request $request, $id)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = Auth::user();
        $original = Experience::find($id);

        if (!$original) {
            return response()->json(['message' => 'Post not found'], 404);
        }

        // Vérifier si l'utilisateur n'est pas le propriétaire
        if ($original->user_id == $user->id) {
            return response()->json(['message' => 'Vous ne pouvez pas partager votre propre publication'], 400);
        }

        // Créer le post partagé dans la table experiences
        $sharedPost = Experience::create([
            'user_id' => $user->id,
            'title' => null,
            'content' => $original->content,
            'shared_from' => $original->id,
            'media_path' => $original->media_path,
            'media_type' => $original->media_type,
        ]);

        // Enregistrer le partage dans la table shares
        Share::create([
            'experience_id' => $original->id,
            'user_id' => $user->id
        ]);

        // Envoyer notification au propriétaire du post original
        if ($original->user_id != $user->id) {
            $original->user->notify(new AppNotification([
                'type' => 'share',
                'actor_id' => $user->id,
                'actor_name' => $user->name,
                'experience_id' => $original->id,
                'message' => '🔄 a partagé votre publication: ' . $user->name
            ]));
        }

        $response = [
            'message' => 'Post shared successfully',
            'post' => $sharedPost->load('user:id,name,profile_pic')
        ];

        if ($sharedPost->media_path) {
            $response['post']->media_url = asset('storage/' . $sharedPost->media_path);
        }

        return response()->json($response, 201);
    }

    /**
     * Récupérer les partages d'une publication
     */
    public function getShares($id)
    {
        $experience = Experience::find($id);
        if (!$experience) {
            return response()->json(['error' => 'Experience not found'], 404);
        }

        $shares = Share::where('experience_id', $id)
            ->with('user:id,name,profile_pic')
            ->with('experience:id,user_id,content')
            ->get()
            ->map(function ($share) {
                return [
                    'id' => $share->id,
                    'user' => $share->user,
                    'shared_at' => $share->created_at,
                    'original_post' => $share->experience
                ];
            });

        return response()->json([
            'shares_count' => $shares->count(),
            'shares' => $shares
        ]);
    }

    /**
     * Récupérer tous les posts partagés par l'utilisateur
     */
    public function myShares()
    {
        $user = Auth::user();

        $sharedPosts = Experience::whereNotNull('shared_from')
            ->where('user_id', $user->id)
            ->with(['user:id,name,profile_pic', 'original.user:id,name,profile_pic'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['shares' => $sharedPosts]);
    }

    /**
     * Supprimer un partage
     */
    public function unshare($id)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = Auth::user();

        // Trouver le post partagé
        $sharedPost = Experience::where('id', $id)
            ->where('user_id', $user->id)
            ->whereNotNull('shared_from')
            ->first();

        if (!$sharedPost) {
            return response()->json(['error' => 'Shared post not found or unauthorized'], 404);
        }

        // Supprimer le partage de la table shares
        Share::where('experience_id', $sharedPost->shared_from)
            ->where('user_id', $user->id)
            ->delete();

        // Supprimer le post partagé
        $sharedPost->delete();

        return response()->json(['status' => 'unshared']);
    }
}
