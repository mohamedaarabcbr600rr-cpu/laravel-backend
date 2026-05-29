<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Experience;
use App\Models\Like;
use Illuminate\Support\Facades\Auth;
use App\Notifications\AppNotification;

class LikeController extends Controller
{
    /**
     * Toggle like/unlike pour une expérience avec plusieurs types de réactions
     */
public function toggle(Request $request, $id)
{
    if (!Auth::check()) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $user = Auth::user();
    $reactionType = $request->input('reaction_type', 'like');

    $validTypes = ['like', 'love', 'haha', 'wow', 'sad', 'angry'];
    if (!in_array($reactionType, $validTypes)) {
        $reactionType = 'like';
    }

    $experience = Experience::findOrFail($id);

    // Vérifie si la même réaction existe déjà
    $existing = Like::where('user_id', $user->id)
        ->where('experience_id', $id)
        ->first();

    // Toggle off si même réaction
    if ($existing && $existing->reaction_type === $reactionType) {
        $existing->delete();
        return response()->json(['status' => 'unliked']);
    }

    // Supprime l'ancienne réaction et crée la nouvelle
    Like::where('user_id', $user->id)
        ->where('experience_id', $id)
        ->delete();

    Like::create([
        'user_id' => $user->id,
        'experience_id' => $id,
        'reaction_type' => $reactionType
    ]);

    // ✅ ENVOIE LA NOTIFICATION (seulement si ce n'est pas son propre post)
    if ($experience->user_id !== $user->id) {
        $experience->user->notify(new AppNotification([
            'type' => 'like',
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'actor_avatar' => $user->profile_pic
                ? asset('storage/' . str_replace('storage/', '', $user->profile_pic))
                : null,
            'experience_id' => $experience->id,
            'post_title' => $experience->title ?? 'Publication',
            'message' => '👍 a aimé votre publication',
            'reaction_type' => $reactionType
        ]));
    }

    return response()->json([
        'status' => 'liked',
        'reaction_type' => $reactionType
    ]);
}
    /**
     * Récupérer les réactions pour une expérience
     */
    public function getReactions($id)
{
    $experience = Experience::find($id);

    if (!$experience) {
        return response()->json(['error' => 'Experience not found'], 404);
    }

    $counts = Like::where('experience_id', $id)
        ->selectRaw('reaction_type, COUNT(*) as count')
        ->groupBy('reaction_type')
        ->pluck('count', 'reaction_type');

    return response()->json([
        'reactions_count' => [
            'like' => $counts['like'] ?? 0,
            'love' => $counts['love'] ?? 0,
            'haha' => $counts['haha'] ?? 0,
            'wow' => $counts['wow'] ?? 0,
            'sad' => $counts['sad'] ?? 0,
            'angry' => $counts['angry'] ?? 0,
        ]
    ]);
}
    
}
