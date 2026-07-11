<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdminNotificationController extends Controller
{
    /**
     * GET /api/admin/notifications
     * List all active notifications, newest first
     */
    public function index(): JsonResponse
    {
        $notifications = AdminNotification::active()
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'notifications' => $notifications,
        ]);
    }

    /**
     * GET /api/admin/notifications/unread-count
     * Get the count of unread active notifications
     */
    public function unreadCount(): JsonResponse
    {
        $count = AdminNotification::active()->unread()->count();

        return response()->json([
            'unread_count' => $count,
        ]);
    }

    /**
     * POST /api/admin/notifications/mark-all-read
     * Mark all active notifications as read
     */
    public function markAllRead(): JsonResponse
    {
        // Hanya mengubah is_read menjadi true. Status (active) TIDAK DIUBAH.
        $updated = AdminNotification::active()
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'message' => 'Notifications marked as read',
            'updated_count' => $updated,
        ]);
    }

    /**
     * PATCH /api/admin/notifications/{id}/read
     * Mark a specific notification as read
     */
    public function markAsRead(int $id): JsonResponse
    {
        $notification = AdminNotification::findOrFail($id);
        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read',
            'data' => $notification,
        ]);
    }

    /**
     * DELETE /api/admin/notifications/{id}
     * Delete notification (swipe to delete)
     */
    public function destroy(int $id): JsonResponse
    {
        $notification = AdminNotification::findOrFail($id);
        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted successfully',
        ]);
    }
}