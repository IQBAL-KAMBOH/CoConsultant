<?php

use App\Http\Controllers\Api\FilePermissionController;
use App\Http\Controllers\Api\FileReportController;
use App\Http\Controllers\Api\FilesController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\MicrosoftAuthController;
use App\Http\Controllers\OneDriveController;
use App\Http\Controllers\Api\NotificationController;

// Auth Routes
Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/login', [AuthController::class, 'login'])->name('login');





Route::middleware(['jwt.auth'])->group(function () {

    // AUth Routes
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('jwt.auth')->name('logout');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('jwt.auth')->name('refresh');
    Route::post('/me', [AuthController::class, 'me'])->name('me');
    Route::post('/profile/update', [AuthController::class, 'updateProfile'])->name('profile.update');


    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class)->middleware('permission:roles.manage');
    Route::apiResource('permissions', PermissionController::class)->middleware('permission:permissions.manage');


    Route::get('/files', [FilesController::class, 'listFiles'])->middleware('permission:files.list');
    Route::post('/files/upload', [FilesController::class, 'upload'])->middleware('permission:files.upload');
    Route::delete('/files/{filename}', [FilesController::class, 'deleteFile'])->middleware('permission:files.delete');
    Route::post('/folders/create', [FilesController::class, 'createFolder'])->middleware('permission:files.update');
    Route::delete('/folders', [FilesController::class, 'deleteFolder'])->middleware('permission:files.update');


    // File Permissions
    Route::post('/files/permissions/assign', [FilePermissionController::class, 'assign'])->middleware('permission:file-permissions.assign');
    Route::post('/files/permissions/remove', [FilePermissionController::class, 'remove'])->middleware('permission:file-permissions.remove');
    Route::get('/files/permissions/list/{fileId}', [FilePermissionController::class, 'list'])->middleware('permission:file-permissions.list');
    Route::get('/files/permissions/user/{userId}', [FilePermissionController::class, 'listByUser'])->middleware('permission:file-permissions.user-list');




    Route::prefix('onedrive')->group(function () {
        Route::get('/list', [OneDriveController::class, 'root'])->middleware('permission:files.list');
        Route::post('/folders/create', [OneDriveController::class, 'createFolder'])->middleware('permission:files.create');
        Route::post('/upload', [OneDriveController::class, 'upload'])->middleware('permission:files.upload');
        Route::delete('/delete/{itemId}', [OneDriveController::class, 'deleteItem'])->middleware('permission:files.delete');
        Route::get('/sync', [OneDriveController::class, 'sync'])->middleware('permission:files.sync');
        Route::post('/move/{fileId}', [OneDriveController::class, 'move'])->middleware('permission:files.move');
        Route::post('/rename/{fileId}', [OneDriveController::class, 'rename'])->middleware('permission:files.rename');
        Route::get('/file/{id}/download-url', [OneDriveController::class, 'getFileDownloadUrl'])->middleware('permission:files.download');
        Route::post('/trash/{id}', [OneDriveController::class, 'trash'])->middleware('permission:files.trash');
        Route::post('/bulk-trash', [OneDriveController::class, 'bulkTrash'])->middleware('permission:files.bulkTrash');
        Route::post('/restore/{id}', [OneDriveController::class, 'restore'])->middleware('permission:files.restore');
        Route::post('/bulk-restore', [OneDriveController::class, 'bulkRestore'])->middleware('permission:files.bulk-restore');
        Route::get('/trashed', [OneDriveController::class, 'trashed'])->middleware('permission:files.trashed');
        Route::get('/storage-usage', [OneDriveController::class, 'storageUsage'])->middleware('permission:files.storage-usage');
        Route::get('/recent-files', [OneDriveController::class, 'recentFiles'])->middleware('permission:files.recent');
    });

    // File Reports
    Route::prefix('files/reports')->group(function () {
        Route::post('/generate', [FileReportController::class, 'generate'])->middleware('permission:reports.generate');
        Route::get('/', [FileReportController::class, 'list'])->middleware('permission:reports.view');
    });
    // Starred Files
    Route::prefix('starred-files')->group(function () {
        Route::get('/', [AuthController::class, 'starredFiles'])->middleware('permission:starred-files.list');
        Route::post('/{id}/star', [AuthController::class, 'toggleStar'])->middleware('permission:starred-files.toggle');
    });

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/unread', [NotificationController::class, 'unread'])->middleware('permission:notifications.unread');
        Route::post('/mark-read', [NotificationController::class, 'markBulkRead'])->middleware('permission:notifications.mark-read');
        Route::delete('/delete', [NotificationController::class, 'bulkDelete'])->middleware('permission:notifications.delete');
    });
});
