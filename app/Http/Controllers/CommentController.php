<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Comment;
use App\Models\Experience;
use Illuminate\Support\Facades\Auth;
use App\Notifications\AppNotification;
use App\Models\CommentLike;

class CommentController extends Controller
{
    /**
     * Stocker un nouveau commentaire
     */
   public function store(Request $request, $id)
{
    $user = $request->user();
    if (!$user) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $request->validate([
        'content' => 'required|string|max:1000',
    ]);

    $experience = Experience::find($id);
    if (!$experience) {
        return response()->json(['error' => 'Experience not found'], 404);
    }

    $comment = Comment::create([
        'user_id' => $user->id,
        'experience_id' => $experience->id,
        'content' => $request->content,
    ]);

    $comment->load('user:id,name,profile_pic');

    // ✅ METTRE LA NOTIFICATION AVANT LE RETURN
    if ($experience->user_id != $user->id) {
        $experience->user->notify(new AppNotification([
            'type' => 'comment',
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'actor_avatar' => $user->profile_pic 
                ? asset('storage/' . str_replace('storage/', '', $user->profile_pic)) 
                : null,
            'experience_id' => $experience->id,
            'comment_id' => $comment->id,
            'post_title' => $experience->title ?? 'Publication',
            'message' => '💬 a commenté votre publication',
            'comment_preview' => substr($request->content, 0, 100)
        ]));
    }

    // ✅ Return à la fin
    return response()->json([
        'status' => 'commented',
        'comment' => [
            'id' => $comment->id,
            'content' => $comment->content,
            'experience_id' => $comment->experience_id,
            'user' => $comment->user,
            'created_at' => $comment->created_at
        ]
    ], 201);
}

    /**
     * Supprimer un commentaire
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $comment = Comment::find($id);
        if (!$comment) {
            return response()->json(['error' => 'Comment not found'], 404);
        }

        // Vérifier que l'utilisateur est le propriétaire du commentaire
        if ($comment->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized - Vous ne pouvez pas supprimer ce commentaire'], 403);
        }

        $comment->delete();

        return response()->json([
            'status' => 'deleted',
            'comment_id' => $id
        ]);
    }

    /**
     * Mettre à jour un commentaire
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $comment = Comment::find($id);
        if (!$comment) {
            return response()->json(['error' => 'Comment not found'], 404);
        }

        if ($comment->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'content' => 'required|string|max:1000',
        ]);

        $comment->update([
            'content' => $request->content
        ]);

        $comment->load('user:id,name,profile_pic');

        return response()->json([
            'status' => 'updated',
            'comment' => $comment
        ]);
    }
    // Like / réaction sur commentaire
public function like(Request $request, $id)
{
    $user = $request->user();
    if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

    $comment = Comment::find($id);
    if (!$comment) return response()->json(['error' => 'Comment not found'], 404);

    $request->validate([
        'reaction_type' => 'nullable|string|in:like,love,haha,wow,sad,angry'
    ]);

    $existing = CommentLike::where('user_id', $user->id)
                           ->where('comment_id', $id)
                           ->first();

    if ($existing) {
        if ($request->reaction_type === null || $existing->reaction_type === $request->reaction_type) {
            // Toggle off
            $existing->delete();
            return response()->json(['status' => 'unliked']);
        }
        // Changer de réaction
        $existing->update(['reaction_type' => $request->reaction_type]);
        return response()->json(['status' => 'updated', 'reaction_type' => $request->reaction_type]);
    }

    CommentLike::create([
        'user_id' => $user->id,
        'comment_id' => $id,
        'reaction_type' => $request->reaction_type ?? 'like',
    ]);

    return response()->json(['status' => 'liked', 'reaction_type' => $request->reaction_type ?? 'like']);
}

// Reply sur commentaire
public function reply(Request $request, $id)
{
    $user = $request->user();
    if (!$user) return response()->json(['error' => 'Unauthorized'], 401);

    $comment = Comment::find($id);
    if (!$comment) return response()->json(['error' => 'Comment not found'], 404);

    $request->validate([
        'content' => 'required|string|max:1000',
    ]);

    $reply = Comment::create([
        'user_id' => $user->id,
        'experience_id' => $comment->experience_id,
        'parent_id' => $id,
        'content' => $request->content,
    ]);

    $reply->load('user:id,name,profile_pic');

    return response()->json([
        'status' => 'replied',
        'comment' => $reply
    ], 201);
}
}
