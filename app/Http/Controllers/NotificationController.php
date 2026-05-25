<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * Récupérer toutes les notifications de l'utilisateur
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json(['error' => 'Non authentifié'], 401);
            }
            
            $notifications = $user->notifications()->paginate(20);
            
            return response()->json([
                'notifications' => $notifications,
                'unread_count' => $user->unreadNotifications()->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }
    
    /**
     * Récupérer le nombre de notifications non lues
     */
    public function unreadCount(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json(['error' => 'Non authentifié'], 401);
            }
            
            return response()->json([
                'unread_count' => $user->unreadNotifications()->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }
    
    /**
     * Marquer une notification comme lue
     */
    public function markAsRead(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json(['error' => 'Non authentifié'], 401);
            }
            
            $notification = DatabaseNotification::findOrFail($id);
            
            // Vérifier que la notification appartient à l'utilisateur
            if ($notification->notifiable_id != $user->id) {
                return response()->json(['error' => 'Non autorisé'], 403);
            }
            
            $notification->markAsRead();
            
            return response()->json(['message' => 'Notification marquée comme lue']);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }
    
    /**
     * Marquer toutes les notifications comme lues
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json(['error' => 'Non authentifié'], 401);
            }
            
            $user->unreadNotifications->markAsRead();
            
            return response()->json(['message' => 'Toutes les notifications ont été marquées comme lues']);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}