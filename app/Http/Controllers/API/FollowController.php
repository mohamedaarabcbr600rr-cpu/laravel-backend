<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Notifications\AppNotification; // استيراد الـ Notification

class FollowController extends Controller
{
    // ✅ اقتراحات المتابعة مع الصور الشخصية
    public function suggestions(Request $request)
    {
        $authUser = $request->user();

        $users = User::where('id', '!=', $authUser->id)
            ->with('followers:id')
            ->limit(5)
            ->get()
            ->map(function ($u) use ($authUser) {
                // ✅ معالجة profile_pic بشكل صحيح
                $profilePic = null;
                if ($u->profile_pic) {
                    // إذا كان المسار يبدأ بـ storage/ قم بإزالته
                    $path = str_replace('storage/', '', $u->profile_pic);
                    $profilePic = asset('storage/' . $path);
                }
                
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'username' => $u->username,
                    'bio' => $u->bio,
                    'headline' => $u->headline,
                    'profile_pic' => $profilePic,
                    'followers' => $u->followers->pluck('id')->toArray(),
                ];
            });

        return response()->json($users);
    }

    // ✅ FOLLOW
    public function follow(Request $request, $userId)
    {
        $authUser = $request->user();

        if (!$authUser) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($authUser->id == $userId) {
            return response()->json(['message' => 'You cannot follow yourself'], 400);
        }

        $userToFollow = User::findOrFail($userId);

        $authUser->follow($userToFollow);

        // 🔥 إرسال إشعار للمستخدم الذي تمت متابعته
        // Dans FollowController.php - méthode follow()
$userToFollow->notify(new AppNotification([
    'type' => 'follow',
    'actor_id' => $authUser->id,
    'actor_name' => $authUser->name,
    'actor_avatar' => $authUser->profile_pic  // ✅ Changé de actor_profile_pic à actor_avatar
        ? asset('storage/' . str_replace('storage/', '', $authUser->profile_pic)) 
        : null,
    'profile_id' => $authUser->id,  // Pour la redirection
    'message' => '👤 a commencé à vous suivre',
    'followed_at' => now()->toDateTimeString()
]));

        return response()->json([
            'message' => 'Followed successfully'
        ]);
    }

    // ✅ UNFOLLOW
    public function unfollow(Request $request, $userId)
    {
        $authUser = $request->user();

        $userToUnfollow = User::findOrFail($userId);

        $authUser->unfollow($userToUnfollow);

        // 🟡 اختياري: يمكن إرسال إشعار بإلغاء المتابعة (عادة لا يُرسل)
        // لكن إذا أردت يمكنك إضافته:
        // $userToUnfollow->notify(new AppNotification([
        //     'type' => 'unfollow',
        //     'actor_id' => $authUser->id,
        //     'actor_name' => $authUser->name,
        //     'message' => '😢 توقف عن متابعتك: ' . $authUser->name
        // ]));

        return response()->json([
            'message' => 'Unfollowed successfully'
        ]);
    }

    public function followers($userId)
    {
        $followers = User::whereIn('id', function($query) use ($userId) {
            $query->select('follower_id')
                  ->from('follows')
                  ->where('following_id', $userId);
        })->get();

        // ✅ معالجة الصور للمتابعين
        $followersWithImages = $followers->map(function ($user) {
            $profilePic = null;
            if ($user->profile_pic) {
                $path = str_replace('storage/', '', $user->profile_pic);
                $profilePic = asset('storage/' . $path);
            }
            
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'bio' => $user->bio,
                'headline' => $user->headline,
                'profile_pic' => $profilePic,
            ];
        });

        return response()->json($followersWithImages);
    }

    public function following($userId)
    {
        $following = User::whereIn('id', function($query) use ($userId) {
            $query->select('following_id')
                  ->from('follows')
                  ->where('follower_id', $userId);
        })->get();

        // ✅ معالجة الصور للمتابَعين
        $followingWithImages = $following->map(function ($user) {
            $profilePic = null;
            if ($user->profile_pic) {
                $path = str_replace('storage/', '', $user->profile_pic);
                $profilePic = asset('storage/' . $path);
            }
            
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'bio' => $user->bio,
                'headline' => $user->headline,
                'profile_pic' => $profilePic,
            ];
        });

        return response()->json($followingWithImages);
    }
}