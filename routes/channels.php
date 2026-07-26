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
| Group private channel
|--------------------------------------------------------------------------
*/

Broadcast::channel('group.{id}', function ($user, $id) {
    return \App\Models\Group::where('id', $id)
        ->whereHas('users', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        })
        ->exists();
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