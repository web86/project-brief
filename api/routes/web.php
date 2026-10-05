<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'ProjectBrief API']));
Route::get('/api/csrf', fn () => response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store'));
Route::post('/api/admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login');
Route::middleware('admin')->prefix('/api/admin')->group(function () {
    Route::get('/me', [AdminAuthController::class, 'me']);
    Route::post('/logout', [AdminAuthController::class, 'logout']);
});

Route::middleware('admin')->prefix('/api/admin/projects')->group(function () {
    Route::get('/', [ProjectController::class, 'index']);
    Route::post('/', [ProjectController::class, 'store']);
    Route::get('/{project}', [ProjectController::class, 'show']);
    Route::patch('/{project}', [ProjectController::class, 'update']);
});
