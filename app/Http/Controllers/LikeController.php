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

    // 👉 supprime SEULEMENT la réaction de cet user sur ce post
    Like::where('user_id', $user->id)
        ->where('experience_id', $id)
        ->delete();

    // 👉 si même réaction → toggle off
    $existingSame = Like::where('user_id', $user->id)
        ->where('experience_id', $id)
        ->where('reaction_type', $reactionType)
        ->first();

    if ($existingSame) {
        $existingSame->delete();
        return response()->json(['status' => 'unliked']);
    }

    // 👉 create new reaction
    Like::create([
        'user_id' => $user->id,
        'experience_id' => $id,
        'reaction_type' => $reactionType
    ]);

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
