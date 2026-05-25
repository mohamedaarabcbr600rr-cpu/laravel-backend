<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class MessageController extends Controller
{
    /**
     * Typing indicator storage (in-memory)
     * Note: Use Redis/database for production with multiple servers
     */
    private static $typing = [];

    /*
    |--------------------------------------------------------------------------
    | CONVERSATIONS
    |--------------------------------------------------------------------------
    */

    /**
     * Create or get existing conversation between two users
     * Route: POST /api/messages/conversations
     */
    public function createConversation(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'auth_user_id' => 'required|exists:users,id'
        ]);

        $authUserId = $request->auth_user_id;

        // Check if conversation already exists
        $conversation = Conversation::where(function($q) use ($request, $authUserId) {
            $q->where('user_one', $authUserId)
              ->where('user_two', $request->user_id);
        })->orWhere(function($q) use ($request, $authUserId) {
            $q->where('user_one', $request->user_id)
              ->where('user_two', $authUserId);
        })->first();

        // Create new conversation if not exists
        if (!$conversation) {
            $conversation = Conversation::create([
                'user_one' => $authUserId,
                'user_two' => $request->user_id,
            ]);
        }

        return response()->json([
            'id' => $conversation->id,
            'other_user' => User::find($request->user_id),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | MESSAGES
    |--------------------------------------------------------------------------
    */

    /**
     * Send a new message (text + image attachment)
     * Route: POST /api/messages/{id}
     */
    public function sendMessage(Request $request, $conversationId)
    {
        $request->validate([
            'content' => 'nullable|string',
            'user_id' => 'required|exists:users,id',
            'file' => 'nullable|file|max:10240' // max 10MB
        ]);

        // Handle file upload
        $filePath = null;
        $fileType = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $filePath = $file->store('messages', 'public');
            $fileType = $file->getMimeType();
        }

        // Create message
        $message = Message::create([
            'conversation_id' => $conversationId,
            'user_id' => $request->user_id,
            'content' => $request->content,
            'file_path' => $filePath,
            'file_type' => $fileType,
            'seen' => false
        ]);

        // Update user last seen
        User::where('id', $request->user_id)->update([
            'last_seen' => now()
        ]);

        return response()->json($message->load('user'), 201);
    }

    /**
     * Get all messages in a conversation
     * Route: GET /api/messages/{id}
     */
    public function getMessages($conversationId)
    {
        $messages = Message::where('conversation_id', $conversationId)
            ->with('user')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($messages, 200);
    }

    /**
     * Update an existing message (edit)
     * Route: PUT /api/messages/{id}
     */
    public function updateMessage(Request $request, $id)
    {
        $request->validate([
            'content' => 'required|string'
        ]);

        $message = Message::findOrFail($id);
        $message->update([
            'content' => $request->content
        ]);

        return response()->json($message);
    }

    /**
     * Delete a message
     * Route: DELETE /api/messages/{id}
     */
    public function deleteMessage($id)
    {
        Message::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Mark messages as seen
     * Route: POST /api/messages/{id}/seen
     */
    public function markAsSeen(Request $request, $conversationId)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id'
        ]);

        Message::where('conversation_id', $conversationId)
            ->where('user_id', '!=', $request->user_id)
            ->update(['seen' => true]);

        return response()->json(['success' => true]);
    }

    /*
    |--------------------------------------------------------------------------
    | TYPING INDICATOR
    |--------------------------------------------------------------------------
    */

    /**
     * Set typing status
     * Route: POST /api/messages/{id}/typing
     */
    public function setTyping(Request $request, $conversationId)
    {
        $request->validate([
            'user_id' => 'required',
            'is_typing' => 'required|boolean'
        ]);

        self::$typing[$conversationId] = [
            'user_id' => $request->user_id,
            'is_typing' => $request->is_typing
        ];

        return response()->json(['ok' => true]);
    }

    /**
     * Get typing status
     * Route: GET /api/messages/{id}/typing
     */
    public function getTyping($conversationId)
    {
        return response()->json(
            self::$typing[$conversationId] ?? [
                'is_typing' => false,
                'user_id' => null
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CONNECTIONS (USERS)
    |--------------------------------------------------------------------------
    */

    /**
     * Get all connections (users) with online status
     * Route: GET /api/connections
     */
    public function getConnections()
    {
        $users = User::all()->map(function($user) {
            $isOnline = false;

            if ($user->last_seen) {
                $isOnline = \Carbon\Carbon::parse($user->last_seen)
                    ->diffInSeconds(now()) < 60;
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'last_seen' => $user->last_seen,
                'online' => $isOnline
            ];
        });

        return response()->json($users, 200);
    }
}