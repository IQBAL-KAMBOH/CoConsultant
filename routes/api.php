<?php

use App\Http\Controllers\Api\FilePermissionController;
use App\Http\Controllers\Api\FilesController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\MicrosoftAuthController;
use App\Http\Controllers\OneDriveController;

// Auth Routes
Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/login', [AuthController::class, 'login'])->name('login');





Route::middleware(['jwt.auth'])->group(function () {

    // AUth Routes
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('jwt.auth')->name('logout');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('jwt.auth')->name('refresh');
    Route::post('/me', [AuthController::class, 'me'])->name('me');

    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class)->middleware('permission:roles.manage');
    Route::apiResource('permissions', PermissionController::class);

    Route::get('/files', [FilesController::class, 'listFiles'])->middleware('permission:files.list');
    Route::post('/files/upload', [FilesController::class, 'upload'])->middleware('permission:files.upload');
    Route::delete('/files/{filename}', [FilesController::class, 'deleteFile'])->middleware('permission:files.delete');
    Route::post('/folders/create', [FilesController::class, 'createFolder'])->middleware('permission:files.update');
    Route::delete('/folders', [FilesController::class, 'deleteFolder'])->middleware('permission:files.update');


    Route::post('/files/permissions/assign', [FilePermissionController::class, 'assign']);
    Route::post('/files/permissions/remove', [FilePermissionController::class, 'remove']);
    Route::get('/files/permissions/list/{fileId}', [FilePermissionController::class, 'list']);
    Route::get('/files/permissions/user/{userId}', [FilePermissionController::class, 'listByUser']); // 👈 new



    Route::prefix('onedrive')->group(function () {
        // List root drive items
        Route::get('/list', [OneDriveController::class, 'root']);
        // Create folder
        Route::post('/folders/create', [OneDriveController::class, 'createFolder']);
        // Upload file
        Route::post('/upload', [OneDriveController::class, 'upload']);
        // Delete file/folder
        Route::delete('/delete/{itemId}', [OneDriveController::class, 'deleteItem']);
        Route::get('/sync', [OneDriveController::class, 'sync']);
        Route::post('/move/{fileId}', [OneDriveController::class, 'move']);
    });
});
