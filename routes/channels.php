<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Conversation;

/*
|--------------------------------------------------------------------------
| Existing user private channel
|--------------------------------------------------------------------------
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| Conversation private channel
|--------------------------------------------------------------------------
*/

Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conversation = Conversation::find($id);

    if (! $conversation) {
        return false;
    }

    return $conversation->user_one === $user->id
        || $conversation->user_two === $user->id;
});

/*
|--------------------------------------------------------------------------
| Presence channel
|--------------------------------------------------------------------------
*/

Broadcast::channel('presence-online', function ($user) {
    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});