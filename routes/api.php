<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\ExperienceController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\API\FollowController;
use Illuminate\Http\Request;
use App\Http\Controllers\API\ProfileController;  // ← API (majuscule)use App\Http\Controllers\MessageController;
use App\Models\User;
use App\Models\Message;
use App\Http\Controllers\AIController;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\API\StoryController; // أضف هاد الـ use فـ البداية

use App\Http\Controllers\NotificationController;
use App\Http\Controllers\AIStudyController;
use App\Http\Controllers\MessageController;



use App\Http\Controllers\UserController;
use App\Http\Controllers\AdminController;
// Routes protégées par authentification
Route::middleware('auth:sanctum')->group(function () {
    // Profil
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile/update', [ProfileController::class, 'update']);
    Route::post('/profile/update', [ProfileController::class, 'update']); // Pour supporter FormData avec POST
    Route::get('/profile/stats/{userId?}', [ProfileController::class, 'stats']);
});

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/experiences', [ExperienceController::class,'index']);

/*
|--------------------------------------------------------------------------
| Protected Routes (Sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function() {

    Route::post('/experiences', [ExperienceController::class,'store']);
    Route::post('/logout', [AuthController::class,'logout']);

    Route::get('/profile', function (Request $request) {
        return $request->user();
    });

   // 

    Route::get('/users/{id}/experiences', function ($id) {
    return \App\Models\Experience::with([
        'user',
        'likes',
        'comments.user'
    ])
    ->where('user_id', $id)
    ->latest()
    ->get();
});



Route::middleware('auth:sanctum')->group(function () {

    // Experiences
    Route::get('/experiences', [ExperienceController::class, 'index']);
    Route::post('/experiences', [ExperienceController::class, 'store']);
    Route::get('/experiences/{id}', [ExperienceController::class, 'show']);
    Route::put('/experiences/{id}', [ExperienceController::class, 'update']);
    Route::delete('/experiences/{id}', [ExperienceController::class, 'destroy']);

    // ✅ Likes avec réactions
    Route::post('/experiences/{id}/like', [LikeController::class, 'toggle']);
    Route::get('/experiences/{id}/reactions', [LikeController::class, 'getReactions']);

    // ✅ Comments avec suppression
    Route::post('/experiences/{id}/comment', [CommentController::class, 'store']);
    Route::delete('/comments/{id}', [CommentController::class, 'destroy']);

    
    // ✅ Shares
    Route::post('/experiences/{id}/share', [ShareController::class, 'share']);
    Route::get('/experiences/{id}/shares', [ShareController::class, 'getShares']);
    Route::get('/my-shares', [ShareController::class, 'myShares']);

    // Amis pour le bouton Envoyer
    Route::get('/friends', function () {
        return response()->json(auth()->user()->friends ?? []);
    });
});
});

/*
|--------------------------------------------------------------------------
| Test Route
|--------------------------------------------------------------------------
*/

Route::get('/test', function () {
    return App\Models\Experience::with('likes')->get();
});

Route::middleware('auth:sanctum')->group(function () {
    // اقتراحات المتابعة
    Route::get('/users/suggestions', [FollowController::class, 'suggestions']);
    
    // متابعة وإلغاء متابعة
    Route::post('/users/{user}/follow', [FollowController::class, 'follow']);
    Route::delete('/users/{user}/follow', [FollowController::class, 'unfollow']);
    
    // Toggle (اختياري)
    Route::post('/users/{user}/toggle-follow', [FollowController::class, 'toggleFollow']);
    
    // التحقق من حالة المتابعة
    Route::get('/users/{user}/follow-status', [FollowController::class, 'checkFollowStatus']);

    // Followers
    Route::get('/users/{user}/followers', [FollowController::class, 'followers']);

    // Following
    Route::get('/users/{user}/following', [FollowController::class, 'following']);

});


//notifications 
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
});




// ✅ Create or get conversation




Route::middleware('auth:sanctum')->group(function () {
    // ← Ajoute ici AVANT les routes {id}
    Route::get('/messages/unread-count', [MessageController::class, 'unreadCount']);
    Route::get('/messages/conversations', [MessageController::class, 'getConversations']);
    Route::post('/messages/conversations', [MessageController::class, 'createConversation']);
    
    // Routes avec {id} après
    Route::post('/messages/{id}', [MessageController::class, 'sendMessage']);
    Route::get('/messages/{id}', [MessageController::class, 'getMessages']);
    Route::put('/messages/{id}', [MessageController::class, 'updateMessage']);
    Route::delete('/messages/{id}', [MessageController::class, 'deleteMessage']);
    Route::post('/messages/{id}/seen', [MessageController::class, 'markAsSeen']);
    Route::get('/messages/{id}/typing', [MessageController::class, 'getTyping']);
    Route::post('/messages/{id}/typing', [MessageController::class, 'setTyping']);
    Route::get('/connections', [MessageController::class, 'getConnections']);
});
// AI Routes






// Routes publiques (sans auth)
Route::post('/ask-ai', [AIController::class, 'askAI']); // à mettre dans auth si besoin
Route::post('/generate-summary', [AIController::class, 'generateSummary']);
Route::post('/generate-qcm', [AIController::class, 'generateQCM']);
Route::post('/generate-flashcards', [AIController::class, 'generateFlashcards']);
Route::post('/explain-concept', [AIController::class, 'explainConcept']);

// ✅ Routes protégées avec auth
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/save-score', [AIController::class, 'saveScore']);
    Route::get('/qcm-history', [AIController::class, 'history']);
    Route::get('/ai-coach', [AIController::class, 'aiCoach']);
    Route::get('/student-dashboard', function () {
        $user = auth()->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        return response()->json([
            'profile' => $user->profile
        ]);
    });
    
    Route::get('/ai-plan', function () {
        $user = auth()->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $profile = $user->profile;
        
        if (!$profile) {
            return response()->json(['error' => 'Profile not found'], 404);
        }
        
        $weakPoints = json_decode($profile->points_faibles ?? "[]", true);
        
        $prompt = "Donne un plan de révision basé sur ces points faibles:\n"
            . implode(", ", $weakPoints);
        
        $response = Http::post('http://localhost:11434/api/generate', [
            'model' => 'llama3.2:3b',
            'prompt' => $prompt,
            'stream' => false
        ]);
        
        return response()->json([
            'plan' => $response['response'] ?? 'Aucune réponse de l\'IA'
        ]);
    });
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::middleware('auth:sanctum')->get('/ai-coach', [AIController::class, 'aiCoach']);





// ... باقي الكود ديالك ...

Route::middleware('auth:sanctum')->group(function () {
    
    // ========== STORIES ROUTES ==========
    // ✅ Get all stories (following & user's own)
    Route::get('/stories', [StoryController::class, 'index']);
    
    // ✅ Create a new story (upload)
    Route::post('/stories', [StoryController::class, 'store']);
    
    // ✅ Get a specific story
    Route::get('/stories/{story}', [StoryController::class, 'show']);
    
    // ✅ Delete a story (24h expiration)
    Route::delete('/stories/{story}', [StoryController::class, 'destroy']);
    
    // ✅ Get user's stories only
    Route::get('/users/{user}/stories', [StoryController::class, 'userStories']);
    
    // ✅ Mark story as viewed
    Route::post('/stories/{story}/view', [StoryController::class, 'markAsViewed']);
    
    
});




// ✅ IMPORTANT : La route 'suggestions' doit être AVANT /{id}
// sinon Laravel va essayer de trouver un user avec id="suggestions"
Route::get('/users/suggestions', [UserController::class, 'suggestions']);
Route::get('/users/username/{username}', [UserController::class, 'showByUsername']);

Route::get('/users/{id}', [UserController::class, 'show']);
Route::get('/users/{id}/experiences', [UserController::class, 'experiences']);
Route::get('/users/{id}/stories', [UserController::class, 'stories']);
Route::get('/users/{id}/followers', [UserController::class, 'followers']);
Route::get('/users/{id}/following', [UserController::class, 'following']);

// ✅ Routes protégées (nécessitent auth)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/users/{id}/follow', [UserController::class, 'follow']);
    Route::delete('/users/{id}/follow', [UserController::class, 'unfollow']);
});




// api admin routes
// Admin routes (protégées par authentification)
// Routes publiques
// Routes publiques
Route::post('/admin/login', [AdminController::class, 'adminLogin']);

// Routes protégées (nécessitent token admin)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/admin/stats', [AdminController::class, 'stats']);
    Route::get('/admin/detailed-stats', [AdminController::class, 'detailedStats']);
    Route::get('/admin/active-users-today', [AdminController::class, 'activeUsersToday']);
    Route::get('/admin/users-by-country', [AdminController::class, 'usersByCountry']);
    Route::post('/admin/test-update-active', [AdminController::class, 'testUpdateActive']);
});



//study material & focus session routes




Route::middleware('auth:sanctum')->group(function () {
    Route::post('/study/upload', [AIStudyController::class, 'upload']);
    Route::get('/study/generate-plan/{materialId}', [AIStudyController::class, 'generatePlan']);

    Route::post('/focus/start', [AIStudyController::class, 'startSession']);
    Route::get('/focus/current-task/{sessionId}', [AIStudyController::class, 'currentTask']);
    Route::post('/focus/complete-task', [AIStudyController::class, 'completeTask']);
    Route::get('/focus/review/{sessionId}', [AIStudyController::class, 'generateReview']);
    Route::post('/focus/finalize/{sessionId}', [AIStudyController::class, 'finalizeSession']);
    Route::get('/focus/history', [AIStudyController::class, 'history']);
});

// Comment routes (likes & replies)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/comments/{id}/like', [CommentController::class, 'like']);
    Route::post('/comments/{id}/reply', [CommentController::class, 'reply']);
});