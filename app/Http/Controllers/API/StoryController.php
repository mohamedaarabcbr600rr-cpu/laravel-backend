<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class StoryController extends Controller
{
    /**
     * Get stories (following + own)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Get IDs of users that the current user follows
        $followingIds = $user->following()->pluck('following_id')->toArray();
        $followingIds[] = $user->id; // Include own stories
        
        // Get stories that are less than 24 hours old
        $stories = Story::whereIn('user_id', $followingIds)
            ->where('created_at', '>=', now()->subHours(24))
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($story) use ($user) {
                return [
                    'id' => $story->id,
                    'user_id' => $story->user_id,
                    'user_name' => $story->user->name,
                    'user_avatar' => $story->user->profile_pic,
                    'story_url' => $story->story_url,
                    'type' => $story->type,
                    'views' => $story->views,
                    'hasStory' => true,
                    'is_viewed' => $story->views()->where('user_id', $user->id)->exists(),
                    'created_at' => $story->created_at,
                    'time_ago' => $story->created_at->diffForHumans(),
                ];
            });
        
        return response()->json($stories);
    }
    
    /**
     * Upload a new story
     */
    public function store(Request $request)
    {
        $request->validate([
            'story' => 'required|file|mimes:jpg,jpeg,png,mp4,mov|max:51200', // max 50MB
        ]);
        
        $user = $request->user();
        $file = $request->file('story');
        
        // Determine file type
        $type = str_starts_with($file->getMimeType(), 'image/') ? 'image' : 'video';
        
        // Store the file
        $path = $file->store('stories', 'public');
        $url = Storage::url($path);
        
        // Delete old stories (more than 24h)
        Story::where('user_id', $user->id)
            ->where('created_at', '<', now()->subHours(24))
            ->delete();
        
        // Create new story
        $story = Story::create([
            'user_id' => $user->id,
            'story_url' => $url,
            'type' => $type,
            'expires_at' => now()->addHours(24),
        ]);
        
        return response()->json([
            'id' => $story->id,
            'user_id' => $story->user_id,
            'story_url' => $story->story_url,
            'type' => $story->type,
            'hasStory' => true,
            'created_at' => $story->created_at,
        ], 201);
    }
    
    /**
     * Get user's stories only
     */
    // App/Http/Controllers/API/StoryController.php
public function userStories($userId)
{
    $stories = Story::where('user_id', $userId)
        ->where('created_at', '>=', now()->subHours(24))
        ->with('user')
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($story) {
            return [
                'id' => $story->id,
                'user_id' => $story->user_id,
                'user_name' => $story->user->name,
                'story_url' => $story->story_url, // L'URL relative: /storage/stories/xxx.jpg
                'type' => $story->type,
                'hasStory' => true,
                'created_at' => $story->created_at,
                'expires_at' => $story->expires_at,
            ];
        });
    
    return response()->json($stories);
}
    
    /**
     * Get a specific story
     */
    public function show(Story $story)
    {
        // Check if story is still valid (less than 24h)
        if ($story->created_at < now()->subHours(24)) {
            $story->delete();
            return response()->json(['message' => 'Story expired'], 404);
        }
        
        return response()->json([
            'id' => $story->id,
            'user_id' => $story->user_id,
            'story_url' => $story->story_url,
            'type' => $story->type,
            'views' => $story->views()->count(),
            'created_at' => $story->created_at,
        ]);
    }
    
    /**
     * Delete a story
     */
    public function destroy(Story $story)
    {
        // Check if user owns the story
        if ($story->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        // Delete file from storage
        $path = str_replace('/storage/', '', $story->story_url);
        Storage::disk('public')->delete($path);
        
        // Delete story
        $story->delete();
        
        return response()->json(['message' => 'Story deleted successfully']);
    }
    
    /**
     * Mark story as viewed
     */
    public function markAsViewed(Story $story, Request $request)
    {
        $user = $request->user();
        
        // Add view if not already viewed
        if (!$story->views()->where('user_id', $user->id)->exists()) {
            $story->views()->create([
                'user_id' => $user->id,
            ]);
        }
        
        return response()->json(['success' => true]);
    }

    
}