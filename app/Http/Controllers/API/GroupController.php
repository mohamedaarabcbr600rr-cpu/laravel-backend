<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Group;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    /**
     * All groups, with member count and whether the current user has joined
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $groups = Group::withCount('users')->get()->map(function ($group) use ($user) {
            return [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'cover_image' => $group->cover_image,
                'members_count' => $group->users_count,
                'is_member' => $group->users()->where('users.id', $user->id)->exists(),
            ];
        });

        return response()->json($groups);
    }

    /**
     * Groups the current user has joined
     */
    public function myGroups(Request $request)
    {
        $user = $request->user();

        $groups = $user->groups()->withCount('users')->get()->map(function ($group) {
            return [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'cover_image' => $group->cover_image,
                'members_count' => $group->users_count,
                'is_member' => true,
            ];
        });

        return response()->json($groups);
    }

    /**
     * Create a new group (creator automatically joins)
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'cover_image' => 'nullable|string',
        ]);

        $user = $request->user();

        $group = Group::create([
            'name' => $request->name,
            'description' => $request->description,
            'cover_image' => $request->cover_image,
            'created_by' => $user->id,
        ]);

        $group->users()->attach($user->id);

        return response()->json($group, 201);
    }

    /**
     * Join a group
     */
    public function join(Request $request, $id)
    {
        $user = $request->user();
        $group = Group::findOrFail($id);

        $group->users()->syncWithoutDetaching([$user->id]);

        return response()->json(['success' => true]);
    }

    /**
     * Leave a group
     */
    public function leave(Request $request, $id)
    {
        $user = $request->user();
        $group = Group::findOrFail($id);

        $group->users()->detach($user->id);

        return response()->json(['success' => true]);
    }
}