<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\User;
use App\Models\FileHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $now = Carbon::now();

        // Storage
        $storageUsed = File::where('is_trashed', false)->sum('size');
        $storageLastMonth = File::where('is_trashed', false)
            ->where('created_at', '<', $now->startOfMonth())
            ->sum('size');
        $storageGrowth = $storageLastMonth > 0
            ? round((($storageUsed - $storageLastMonth) / $storageLastMonth) * 100, 2)
            : 0;

        // Active users
        $activeUsers = User::where('created_at', '>=', $now->startOfWeek())->count();
        $totalUsers = User::count();

        // Files
        $totalFiles = File::where('is_trashed', false)->count();
        $filesThisMonth = File::where('is_trashed', false)
            ->where('created_at', '>=', $now->startOfMonth())
            ->count();

        // Recent Users (with storage + last active)
        $recentUsers = User::orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($user) {
                $storage = File::where('user_id', $user->id)
                    ->where('is_trashed', false)
                    ->sum('size');


                $lastActive = $user->last_login_at ?? $user->updated_at;

                return [
                    'id'          => $user->id,
                    'name'        => $user->name,
                    'email'       => $user->email,
                    'storage'     => $this->formatSize($storage),
                    // 'last_active' => $lastActive ? $lastActive->diffForHumans() : 'N/A',
                    // 'status'      => $lastActive && $lastActive->gt(now()->subWeek()) ? 'Active' : 'Inactive',
                ];
            });

        // Recent Activity
        $recentActivity = FileHistory::with(['file:id,name', 'user:id,name'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->map(function ($activity) {
                return [
                    'id'        => $activity->id,
                    'user'      => $activity->user?->name,
                    'action'    => $activity->action,
                    'file'      => $activity->file?->name,
                    'time'      => $activity->created_at->diffForHumans(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'storage' => [
                    'used'       => $this->formatSize($storageUsed),
                    'growth_pct' => $storageGrowth,
                ],
                'users' => [
                    'total' => $totalUsers,
                    'active_this_week' => $activeUsers,
                    'recent'           => $recentUsers,
                ],
                'files' => [
                    'total'       => $totalFiles,
                    'this_month'  => $filesThisMonth,
                ],
                'activity' => $recentActivity,
            ],
        ]);
    }

    private function formatSize($bytes)
    {
        if ($bytes >= 1 << 40) {
            return round($bytes / (1 << 40), 2) . ' TB';
        } elseif ($bytes >= 1 << 30) {
            return round($bytes / (1 << 30), 2) . ' GB';
        } elseif ($bytes >= 1 << 20) {
            return round($bytes / (1 << 20), 2) . ' MB';
        } elseif ($bytes >= 1 << 10) {
            return round($bytes / (1 << 10), 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
