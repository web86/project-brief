<?php

use App\Http\Controllers\AccessLinkController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\ProjectController;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;
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

Route::post('/api/admin/projects/{project}/access-links', [AccessLinkController::class, 'store'])->middleware('admin');
Route::delete('/api/admin/projects/{project}/access-links/{token}', [AccessLinkController::class, 'destroy'])->middleware('admin');
Route::get('/access/{token}', [AccessLinkController::class, 'enter'])->middleware('throttle:access')->name('client.access');
Route::middleware('client')->prefix('/api/client')->group(function () {
    Route::get('/project', fn (Request $r) => new ProjectResource($r->attributes->get('client_project')));
    Route::get('/project/{project}', fn (Project $project) => new ProjectResource($project));
});
