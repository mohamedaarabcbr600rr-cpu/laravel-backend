<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Events\GroupMessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GroupController extends Controller
{
    /**
     * All groups, with member count and whether the current user has joined
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $groups = Group::withCount('users')->get()->map(function ($group) use ($user) {
            return $this->formatGroupSummary($group, $user);
        });

        return response()->json($groups);
    }

    /**
     * Shared formatter: adds last message preview + unread count + membership status
     */
    private function formatGroupSummary(Group $group, $user)
    {
        $membership = $group->users()->where('users.id', $user->id)->first();
        $isMember = $membership !== null;

        $lastMessage = GroupMessage::where('group_id', $group->id)
            ->with('user:id,name')
            ->latest('created_at')
            ->first();

        $unreadCount = 0;
        if ($isMember) {
            $lastReadAt = $membership->pivot->last_read_at;
            $unreadCount = GroupMessage::where('group_id', $group->id)
                ->where('user_id', '!=', $user->id)
                ->when($lastReadAt, fn ($q) => $q->where('created_at', '>', $lastReadAt))
                ->count();
        }

        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'cover_image' => $group->cover_image,
            'members_count' => $group->users_count ?? $group->users()->count(),
            'is_member' => $isMember,
            'is_admin' => (int) $group->created_by === (int) $user->id,
            'invite_token' => $isMember ? $group->invite_token : null,
            'last_message' => $lastMessage ? [
                'content' => $lastMessage->content,
                'user_name' => $lastMessage->user?->name,
                'created_at' => $lastMessage->created_at?->toIso8601String(),
            ] : null,
            'unread_count' => $unreadCount,
        ];
    }

    /**
     * Groups the current user has joined
     */
public function myGroups(Request $request)
    {
        $user = $request->user();

        $groups = $user->groups()->withCount('users')->get()->map(function ($group) use ($user) {
            return $this->formatGroupSummary($group, $user);
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
            'cover_image' => 'nullable|image|max:5120',
        ]);

        $user = $request->user();

        $coverPath = null;
        if ($request->hasFile('cover_image')) {
            $coverPath = $request->file('cover_image')->store('group-covers', 'public');
            $coverPath = '/storage/' . $coverPath;
        }

        $group = Group::create([
            'name' => $request->name,
            'description' => $request->description,
            'cover_image' => $coverPath,
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

   /**
     * List members of a group (for avatar stack in header)
     */
    public function members(Request $request, $id)
    {
        $user = $request->user();
        $group = Group::findOrFail($id);

        if (!$group->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['error' => 'Vous devez rejoindre ce groupe'], 403);
        }

        $members = $group->users()->select('users.id', 'users.name', 'users.profile_pic')->get();

        return response()->json($members);
    }

   /**
     * Join a group via its invite link/token
     */
    public function joinByToken(Request $request, $token)
    {
        $user = $request->user();
        $group = Group::where('invite_token', $token)->first();

        if (!$group) {
            return response()->json(['error' => 'Lien d\'invitation invalide'], 404);
        }

        $group->users()->syncWithoutDetaching([$user->id]);

        return response()->json($this->formatGroupSummary($group->fresh(), $user));
    }

    /**
     * Delete a group (creator/admin only)
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $group = Group::findOrFail($id);

        if ((int) $group->created_by !== (int) $user->id) {
            return response()->json(['error' => 'Seul le créateur du groupe peut le supprimer'], 403);
        }

        $group->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Get all messages in a group (members only)
     */
    public function getMessages(Request $request, $id)
    {
        $user = $request->user();
        $group = Group::findOrFail($id);

        if (!$group->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['error' => 'Vous devez rejoindre ce groupe pour voir les messages'], 403);
        }

        $messages = GroupMessage::where('group_id', $id)
            ->with(['user', 'replyTo.user'])
            ->orderBy('created_at', 'asc')
            ->get();

        $group->users()->updateExistingPivot($user->id, ['last_read_at' => now()]);

        return response()->json($messages);
    }
    
    /**
     * Send a message in a group (members only)
     */
public function sendMessage(Request $request, $id)
    {
        $request->validate([
            'content' => 'nullable|string',
            'file' => 'nullable|file|max:51200', // up to 50MB, to allow short videos
            'reply_to_id' => 'nullable|exists:group_messages,id',
        ]);

        $user = $request->user();
        $group = Group::findOrFail($id);

        if (!$group->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['error' => 'Vous devez rejoindre ce groupe pour envoyer un message'], 403);
        }

        $filePath = null;
        $fileType = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $filePath = $file->store('group-messages', 'public');
            $fileType = $file->getMimeType();
        }

        $message = GroupMessage::create([
            'group_id' => $id,
            'user_id' => $user->id,
            'content' => $request->content,
            'file_path' => $filePath,
            'file_type' => $fileType,
            'reply_to_id' => $request->reply_to_id,
        ]);

        $message->load(['user', 'replyTo.user']);

        broadcast(new GroupMessageSent($message))->toOthers();

        return response()->json($message, 201);
    }

    /**
     * Delete a group message for everyone (soft — keeps history, shows placeholder)
     */
    public function deleteMessage(Request $request, $messageId)
    {
        $user = $request->user();
        $message = GroupMessage::findOrFail($messageId);

        if ((int) $message->user_id !== (int) $user->id) {
            return response()->json(['error' => 'Vous ne pouvez supprimer que vos propres messages'], 403);
        }

        $message->update(['deleted_for_everyone_at' => now()]);
        $message->load(['user', 'replyTo.user']);

        broadcast(new GroupMessageSent($message))->toOthers();

        return response()->json($message);
    }
}