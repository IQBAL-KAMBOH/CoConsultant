<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * List unread notifications
     */
    public function unread(Request $request)
    {

        $notifications = $request->user()->unreadNotifications()->latest()->get();

        return response()->json([
            'status' => 'ok',
            'notifications' => $notifications,
        ]);
    }

    /**
     * Bulk mark notifications as read
     */
    public function markBulkRead(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'string',
        ]);

        $notifications = $request->user()->unreadNotifications()
            ->whereIn('id', $request->ids)
            ->get();

        if ($notifications->isEmpty()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No matching unread notifications found',
            ], 404);
        }

        foreach ($notifications as $notification) {
            $notification->markAsRead();
        }

        return response()->json([
            'status'  => 'ok',
            'message' => $notifications->count() . ' notifications marked as read',
        ]);
    }


    /**
     * Bulk delete notifications
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'string',
        ]);

        $deletedCount = $request->user()->notifications()
            ->whereIn('id', $request->ids)
            ->delete();

        if ($deletedCount === 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No matching notifications found',
            ], 404);
        }

        return response()->json([
            'status'  => 'ok',
            'message' => $deletedCount . ' notifications deleted',
        ]);
    }
}
