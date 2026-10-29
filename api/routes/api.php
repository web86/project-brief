<?php

use App\Http\Controllers\IntegrationApiController;
use App\Http\Controllers\IntegrationReadController;
use App\Http\Controllers\ProjectProvisioningController;
use App\Http\Controllers\ProjectRequestController;
use App\Http\Middleware\AuthenticateIntegration;
use App\Http\Middleware\RecordProvisioningOperation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([AuthenticateIntegration::class])->group(function (): void {
    Route::post('/project-requests', [ProjectRequestController::class, 'store'])->middleware(RecordProvisioningOperation::class);
    Route::get('/project-requests', [ProjectRequestController::class, 'index']);
    Route::get('/project-requests/{uuid}', [ProjectRequestController::class, 'show']);
    Route::post('/project-requests/{uuid}/approve', [ProjectRequestController::class, 'approve'])->middleware(RecordProvisioningOperation::class);
    Route::post('/project-requests/{uuid}/reject', [ProjectRequestController::class, 'reject'])->middleware(RecordProvisioningOperation::class);
    Route::get('/projects', [IntegrationApiController::class, 'projects']);
    Route::post('/projects/provision', [ProjectProvisioningController::class, 'store'])->middleware(RecordProvisioningOperation::class);
    Route::post('/projects', [IntegrationApiController::class, 'store']);
    Route::get('/projects/{uuid}', [IntegrationApiController::class, 'show']);
    Route::get('/projects/{uuid}/tasks', [IntegrationApiController::class, 'tasks']);
    Route::post('/projects/{uuid}/tasks', [IntegrationApiController::class, 'storeTask']);
    Route::get('/projects/{projectUuid}/comments', [IntegrationReadController::class, 'comments']);
    Route::get('/projects/{projectUuid}/comments/{commentUuid}', [IntegrationReadController::class, 'comment']);
    Route::get('/projects/{projectUuid}/tasks/{taskUuid}/attachments', [IntegrationReadController::class, 'taskAttachments']);
    Route::get('/projects/{projectUuid}/comments/{commentUuid}/attachments', [IntegrationReadController::class, 'commentAttachments']);
    Route::get('/attachments/{attachmentUuid}/download', [IntegrationReadController::class, 'download']);
    Route::any('/{path}', fn () => response()->json(['code' => 'not_found', 'message' => 'Endpoint not found.'], 404))->where('path', '.*');
});
