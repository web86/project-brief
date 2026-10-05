<?php

use App\Http\Controllers\AccessLinkController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ProjectClientController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectSectionController;
use App\Http\Controllers\SpaController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskOrderController;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectStructure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', SpaController::class);
Route::get('/api/health', fn () => response()->json(['ok' => true])->header('Cache-Control', 'no-store'))
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ]);
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

Route::middleware('admin')->prefix('/api/admin/projects/{project}')->group(function () {
    Route::get('/clients', [ProjectClientController::class, 'index']);
    Route::post('/clients', [ProjectClientController::class, 'store']);
    Route::patch('/clients/{client}', [ProjectClientController::class, 'update']);
    Route::post('/clients/{client}/access-links', [AccessLinkController::class, 'store']);
    Route::delete('/clients/{client}/access-links/{token}', [AccessLinkController::class, 'destroy']);
    Route::get('/sections', [ProjectSectionController::class, 'index']);
    Route::post('/sections', [ProjectSectionController::class, 'store']);
    Route::patch('/sections/{section}', [ProjectSectionController::class, 'update']);
    Route::delete('/sections/{section}', [ProjectSectionController::class, 'destroy']);
});
Route::get('/access/{token}', [AccessLinkController::class, 'enter'])->middleware('throttle:access')->name('client.access');
Route::middleware('client')->prefix('/api/client')->group(function () {
    Route::get('/me', fn (Request $r) => response()->json(['id' => $r->attributes->get('project_client')->uuid, 'name' => $r->attributes->get('project_client')->name, 'email' => $r->attributes->get('project_client')->email, 'project' => new ProjectResource($r->attributes->get('client_project'))]));
    Route::get('/project', fn (Request $r) => new ProjectResource($r->attributes->get('client_project')));
    Route::get('/project/{project}', fn (Project $project) => new ProjectResource($project));
    Route::get('/project/{project}/sections', fn (Project $project) => response()->json(['data' => $project->briefSections->map(fn ($section) => ProjectStructure::sectionData($section))]));
});

Route::middleware('admin')->prefix('/api/admin')->group(function () {
    Route::get('/projects/{project}/tasks', [TaskController::class, 'index']);
    Route::post('/projects/{project}/tasks', [TaskController::class, 'store'])->middleware('throttle:uploads');
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::patch('/tasks/{task}', [TaskController::class, 'update']);
    Route::post('/tasks/{task}/move', [TaskOrderController::class, 'move']);
    Route::post('/tasks/{task}/reorder', [TaskOrderController::class, 'reorder']);
});
Route::middleware('client')->prefix('/api/client')->group(function () {
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store'])->middleware('throttle:uploads');
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::patch('/tasks/{task}', [TaskController::class, 'update']);
    Route::post('/tasks/{task}/approve', [TaskController::class, 'approve']);
});

Route::post('/api/admin/tasks/{task}/comments', [CommentController::class, 'store'])->middleware(['admin', 'throttle:comments']);
Route::post('/api/client/tasks/{task}/comments', [CommentController::class, 'store'])->middleware(['client', 'throttle:comments']);

foreach (['admin', 'client'] as $actor) {
    Route::middleware($actor)->prefix('/api/'.$actor)->group(function () {
        Route::post('/tasks/{task}/attachments', [AttachmentController::class, 'store'])->middleware('throttle:uploads');
        Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download']);
        Route::get('/attachments/{attachment}/preview', [AttachmentController::class, 'preview']);
    });
}

Route::any('/{fallbackPlaceholder}', SpaController::class)->where('fallbackPlaceholder', '.*')->fallback();
