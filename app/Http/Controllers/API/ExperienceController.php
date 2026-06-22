<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Experience;
use App\Models\ExperienceMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Models\Like;
use Illuminate\Support\Facades\Auth;

class ExperienceController extends Controller
{
    public function index()
    {
        $experiences = Experience::with([
            'user:id,name,profile_pic',
            'likes',
            'comments' => function ($query) {
                $query->with('user:id,name,profile_pic')
                      ->orderBy('created_at', 'desc');
            },
            'original.user:id,name,profile_pic',
            'medias'
        ])
        ->withCount('likes')
        ->orderBy('created_at', 'desc')
        ->get();

        return response()->json($experiences);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'     => 'nullable|string|max:255',
            'content'   => 'nullable|string',
            'media'     => 'nullable|array',
            'media.*'   => 'nullable|file|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $experience = Experience::create([
            'user_id' => $user->id,
            'title'   => $request->title,
            'content' => $request->content,
        ]);

        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $path     = $file->store('media', 'public');
                $mimeType = $file->getMimeType();
                $type     = str_starts_with($mimeType, 'video/') ? 'video' : 'image';

                ExperienceMedia::create([
                    'experience_id' => $experience->id,
                    'path'          => $path,
                    'type'          => $type,
                ]);
            }
        }

        $experience->load(['user:id,name,profile_pic', 'likes', 'medias']);
        $experience->likes_count = 0;

        return response()->json($experience, 201);
    }

    public function show($id)
    {
        $experience = Experience::with([
            'user:id,name,profile_pic',
            'likes',
            'comments.user:id,name,profile_pic',
            'original.user:id,name,profile_pic',
            'medias'
        ])
        ->withCount('likes')
        ->find($id);

        if (!$experience) {
            return response()->json(['error' => 'Experience not found'], 404);
        }

        $experience->likes_count = $experience->likes->count();

        return response()->json($experience);
    }

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
            'title'   => 'nullable|string|max:255',
            'content' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $experience->update([
            'title'   => $request->title   ?? $experience->title,
            'content' => $request->content ?? $experience->content,
        ]);

        $experience->load(['user:id,name,profile_pic', 'likes', 'medias']);
        $experience->likes_count = $experience->likes->count();

        return response()->json($experience);
    }

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

        foreach ($experience->medias as $media) {
            Storage::disk('public')->delete($media->path);
        }

        \DB::table('notifications')
            ->whereJsonContains('data->experience_id', (int)$id)
            ->delete();

        $experience->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function userExperiences($userId)
    {
        $experiences = Experience::with([
            'user:id,name,profile_pic',
            'likes',
            'comments.user:id,name,profile_pic',
            'original.user:id,name,profile_pic',
            'medias'
        ])
        ->withCount('likes')
        ->where('user_id', $userId)
        ->orderBy('created_at', 'desc')
        ->get();

        return response()->json($experiences);
    }
}