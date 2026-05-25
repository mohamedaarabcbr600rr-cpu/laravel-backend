<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Experience;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Models\Like;
use Illuminate\Support\Facades\Auth;

class ExperienceController extends Controller
{
    /**
     * Récupérer toutes les expériences avec leurs relations
     */
    public function index()
    {
        $experiences = Experience::with([
            'user:id,name,profile_pic',
            'likes',
            'comments' => function ($query) {
                $query->with('user:id,name,profile_pic')
                      ->orderBy('created_at', 'desc');
            },
            'original.user:id,name,profile_pic'
        ])
        ->withCount('likes')
        ->orderBy('created_at', 'desc')
        ->get();

        $experiences->each(function ($exp) {
    if ($exp->media_path) {
        $exp->media_url = asset('storage/' . $exp->media_path);
    }

    $exp->reactions_count = [
        'like' => $exp->likes()->where('reaction_type', 'like')->count(),
        'love' => $exp->likes()->where('reaction_type', 'love')->count(),
        'haha' => $exp->likes()->where('reaction_type', 'haha')->count(),
        'wow'  => $exp->likes()->where('reaction_type', 'wow')->count(),
        'sad'  => $exp->likes()->where('reaction_type', 'sad')->count(),
        'angry'=> $exp->likes()->where('reaction_type', 'angry')->count(),
    ];
});

        return response()->json($experiences);
    }

    /**
     * Créer une nouvelle expérience
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'media' => 'nullable|file|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = [
            'user_id' => $user->id,
            'title' => $request->title,
            'content' => $request->content,
        ];

        if ($request->hasFile('media')) {
            $file = $request->file('media');
            $path = $file->store('media', 'public');

            $data['media_path'] = $path;

            $mimeType = $file->getMimeType();
            if (str_starts_with($mimeType, 'image/')) {
                $data['media_type'] = 'image';
            } elseif (str_starts_with($mimeType, 'video/')) {
                $data['media_type'] = 'video';
            }
        }

        $experience = Experience::create($data);

        $experience->load('user:id,name,profile_pic');
        $experience->load('likes');
        $experience->likes_count = 0;

        if ($experience->media_path) {
            $experience->media_url = asset('storage/' . $experience->media_path);
        }

        return response()->json($experience, 201);
    }

    /**
     * Récupérer une expérience spécifique
     */
    public function show($id)
    {
        $experience = Experience::with([
            'user:id,name,profile_pic',
            'likes',
            'comments.user:id,name,profile_pic',
            'original.user:id,name,profile_pic'
        ])
        ->withCount('likes')
        ->find($id);

        if (!$experience) {
            return response()->json(['error' => 'Experience not found'], 404);
        }

        if ($experience->media_path) {
            $experience->media_url = asset('storage/' . $experience->media_path);
        }

        $experience->likes_count = $experience->likes->count();

        return response()->json($experience);
    }

    /**
     * Mettre à jour une expérience
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $experience = Experience::find($id);
        if (!$experience) {
            return response()->json(['error' => 'Experience not found'], 404);
        }

        if ($experience->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'content' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $experience->update([
            'title' => $request->title ?? $experience->title,
            'content' => $request->content ?? $experience->content,
        ]);

        $experience->load('user:id,name,profile_pic');
        $experience->load('likes');
        $experience->likes_count = $experience->likes->count();

        return response()->json($experience);
    }

    /**
     * Supprimer une expérience
     */
   public function destroy(Request $request, $id)
{
    $user = $request->user();
    if (!$user) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $experience = Experience::find($id);
    if (!$experience) {
        return response()->json(['error' => 'Experience not found'], 404);
    }

    if ($experience->user_id !== $user->id) {
        return response()->json(['error' => 'Unauthorized'], 403);
    }

    if ($experience->media_path) {
        Storage::disk('public')->delete($experience->media_path);
    }

    // ✅ SUPPRIME LES NOTIFICATIONS LIÉES À CETTE EXPÉRIENCE
    \App\Models\DatabaseNotification::where('data->experience_id', $id)->delete();
    // Ou si tu utilises le modèle par défaut :
    // \Illuminate\Notifications\DatabaseNotification::where('data->experience_id', $id)->delete();

    $experience->delete();

    return response()->json(['status' => 'deleted']);
}

    /**
     * Récupérer les expériences d'un utilisateur
     */
    public function userExperiences($userId)
    {
        $experiences = Experience::with([
            'user:id,name,profile_pic',
            'likes',
            'comments.user:id,name,profile_pic',
            'original.user:id,name,profile_pic'
        ])
        ->withCount('likes')
        ->where('user_id', $userId)
        ->orderBy('created_at', 'desc')
        ->get();

       $experiences->each(function ($exp) {
    if ($exp->media_path) {
        $exp->media_url = asset('storage/' . $exp->media_path);
    }

    $exp->reactions_count = [
        'like' => $exp->likes()->where('reaction_type', 'like')->count(),
        'love' => $exp->likes()->where('reaction_type', 'love')->count(),
        'haha' => $exp->likes()->where('reaction_type', 'haha')->count(),
        'wow'  => $exp->likes()->where('reaction_type', 'wow')->count(),
        'sad'  => $exp->likes()->where('reaction_type', 'sad')->count(),
        'angry'=> $exp->likes()->where('reaction_type', 'angry')->count(),
    ];
});

        return response()->json($experiences);
    }
}