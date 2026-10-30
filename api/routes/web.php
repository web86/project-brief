<?php

use App\Http\Controllers\AccessLinkController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\AdminProjectRequestController;
use App\Http\Controllers\AiTaskAssistantController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\BrowserProjectExportController;
use App\Http\Controllers\ClientLocaleController;
use App\Http\Controllers\ClientNotificationController;
use App\Http\Controllers\ClientProjectLoginController;
use App\Http\Controllers\ClientSsoController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DeveloperInvitationController;
use App\Http\Controllers\EntryBrandingController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LiveSyncController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\ProjectActivityController;
use App\Http\Controllers\ProjectClientController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDiscussionController;
use App\Http\Controllers\ProjectExportController;
use App\Http\Controllers\ProjectFeedbackController;
use App\Http\Controllers\ProjectSectionController;
use App\Http\Controllers\ProjectTeamController;
use App\Http\Controllers\ProjectTrashController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SpaController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskOrderController;
use App\Http\Controllers\TaskTrashController;
use App\Http\Controllers\TeamChatController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\WorkspaceAdministratorController;
use App\Http\Controllers\WorkspaceAgreementController;
use App\Http\Controllers\WorkspaceBrandingController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectStructure;
use App\Services\SupportedLocales;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware([EnsurePlatformAdmin::class, 'throttle:platform'])->group(function (): void {
    Route::get('/platform', SpaController::class)->name('platform.enter');
    Route::prefix('/api/platform')->controller(PlatformController::class)->group(function (): void {
        Route::get('/overview', 'overview')->name('platform.overview');
        Route::get('/workspaces', 'workspaces')->name('platform.workspaces');
        Route::get('/workspaces/{uuid}', 'workspace')->whereUuid('uuid')->name('platform.workspace');
        Route::get('/usage', 'usage')->name('platform.usage');
        Route::get('/audit', 'audit')->name('platform.audit');
    });
});
Route::get('/', SpaController::class);
Route::get('/api/health', fn () => response()->json(['ok' => true])->header('Cache-Control', 'no-store'))
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ]);
Route::get('/api/csrf', fn () => response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store'));
Route::get('/api/session', SessionController::class);
Route::get('/api/public/workspaces/{workspace}/agreement', [WorkspaceAgreementController::class, 'published'])->whereUuid('workspace')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);
Route::middleware('admin')->controller(WorkspaceAgreementController::class)->group(function (): void {
    Route::get('/api/admin/settings/agreement', 'settings');
    Route::put('/api/admin/settings/agreement', 'save')->middleware('throttle:integration-settings');
    Route::get('/api/admin/agreement', 'document');
    Route::post('/api/admin/agreement/accept', 'accept')->middleware('throttle:account-security');
});
Route::prefix('/api/security')->controller(AccountSecurityController::class)->group(function (): void {
    Route::get('/status', 'show');
    Route::post('/challenge', 'challenge')->middleware('throttle:security-challenge')->block();
    foreach (['login', 'confirm', 'register'] as $purpose) {
        $routes = [
            Route::get('/passkey/'.$purpose.'/options', 'options')->defaults('purpose', $purpose),
            Route::post('/passkey/'.$purpose, 'passkey')->defaults('purpose', $purpose),
        ];
        foreach ($routes as $route) {
            $route->middleware('throttle:security-passkeys')->block();
            if ($purpose !== 'login') {
                $route->middleware('auth');
            }
        }
    }
    Route::middleware(['auth', 'throttle:account-security'])->group(function (): void {
        Route::post('/confirm', 'confirm')->block();
        Route::post('/recommendations/acknowledge', 'acknowledgeRecommendations')->block();
        Route::delete('/totp', 'disableTotp')->block();
        Route::post('/totp/setup', 'setupTotp')->block();
        Route::post('/totp/confirm', 'confirmTotp')->block();
        Route::match(['GET', 'POST'], '/recovery-codes', 'recovery')->block();
        Route::post('/recovery-codes/acknowledge', 'acknowledgeRecovery')->block();
        Route::patch('/passkeys/{id}', 'updatePasskey')->whereNumber('id')->block();
        Route::delete('/passkeys/{id}', 'deletePasskey')->whereNumber('id')->block();
    });
});
Route::get('/api/entry/branding', EntryBrandingController::class)->middleware('throttle:invitation-resolve');
Route::post('/api/admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login')->block();
Route::post('/api/invitations/resolve', [DeveloperInvitationController::class, 'show'])->middleware('throttle:invitation-resolve');
Route::post('/api/invitations/accept', [DeveloperInvitationController::class, 'accept'])->middleware('throttle:invitation-accept');
Route::post('/api/admin/password/inspect', [PasswordRecoveryController::class, 'inspect'])->middleware('throttle:account-reset-inspect');
Route::post('/api/admin/password/forgot', [PasswordRecoveryController::class, 'request'])->middleware('throttle:account-recovery');
Route::post('/api/admin/password/reset', [PasswordRecoveryController::class, 'reset'])->middleware('throttle:account-recovery');
Route::middleware('admin')->prefix('/api/admin')->group(function () {
    Route::get('/account', [AccountController::class, 'show']);
    Route::patch('/account', [AccountController::class, 'update'])->middleware('throttle:profile-update');
    Route::post('/account/email', [AccountController::class, 'email'])->middleware('throttle:account-security');
    Route::post('/account/email/confirm', [AccountController::class, 'confirmEmail'])->middleware('throttle:account-security');
    Route::delete('/account/email', [AccountController::class, 'cancelEmail']);
    Route::post('/account/password', [AccountController::class, 'password'])->middleware('throttle:account-security');
    Route::post('/account/avatar', [AccountController::class, 'avatar'])->middleware('throttle:uploads');
    Route::get('/avatars/{key}', [AccountController::class, 'image']);

    Route::get('/sync', LiveSyncController::class)->middleware('throttle:live-sync');
    Route::get('/notifications', [AdminNotificationController::class, 'show']);
    Route::patch('/notifications', [AdminNotificationController::class, 'update'])->middleware('throttle:notification-update');
    Route::post('/notifications/email/verify', [AdminNotificationController::class, 'verify'])->middleware('throttle:notification-verify');
    Route::delete('/notifications/email/pending', [AdminNotificationController::class, 'cancel']);
    Route::post('/notifications/test', [AdminNotificationController::class, 'test'])->middleware('throttle:notification-test');
    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::post('/workspaces/{workspace}/select', [WorkspaceController::class, 'select']);
    Route::get('/me', [AdminAuthController::class, 'me']);
    Route::patch('/me/locale', [ClientLocaleController::class, 'updateAdmin']);
    Route::post('/logout', [AdminAuthController::class, 'logout']);
});

Route::middleware('admin')->prefix('/api/admin/administrators')->group(function (): void {
    Route::get('/', [WorkspaceAdministratorController::class, 'index']);
    Route::post('/invitations', [WorkspaceAdministratorController::class, 'invite'])->middleware('throttle:account-security');
    Route::post('/invitations/{invitation}/link', [WorkspaceAdministratorController::class, 'link'])->middleware('throttle:account-security');
    Route::post('/invitations/{invitation}/resend', [WorkspaceAdministratorController::class, 'resend'])->middleware('throttle:account-security');
    Route::patch('/invitations/{invitation}', [WorkspaceAdministratorController::class, 'updateInvitation']);
    Route::delete('/invitations/{invitation}', [WorkspaceAdministratorController::class, 'revoke']);
    Route::patch('/members/{member}', [WorkspaceAdministratorController::class, 'update']);
    Route::delete('/members/{member}', [WorkspaceAdministratorController::class, 'destroy']);
});

Route::middleware(['admin', 'throttle:integration-settings'])->prefix('/api/admin/integrations')->group(function (): void {
    Route::get('/', [IntegrationController::class, 'index']);
    Route::post('/sources', [IntegrationController::class, 'source']);
    Route::delete('/sources/{uuid}', [IntegrationController::class, 'deleteSource']);
    Route::patch('/sources/{uuid}/sso', [IntegrationController::class, 'sourceSso']);
    Route::post('/tokens', [IntegrationController::class, 'token']);
    Route::patch('/tokens/{uuid}', [IntegrationController::class, 'updateToken']);
    Route::get('/operations', [IntegrationController::class, 'operations']);
    Route::delete('/tokens/{uuid}', [IntegrationController::class, 'revoke']);
    Route::post('/webhooks', [IntegrationController::class, 'webhook']);
    Route::patch('/webhooks/{uuid}', [IntegrationController::class, 'webhook']);
    Route::delete('/webhooks/{uuid}', [IntegrationController::class, 'destroy']);
    Route::post('/webhooks/{uuid}/rotate', [IntegrationController::class, 'rotate']);
    Route::post('/webhooks/{uuid}/test', [IntegrationController::class, 'test'])->middleware('throttle:integration-test');
    Route::get('/webhooks/{uuid}/deliveries', [IntegrationController::class, 'history']);
    Route::post('/webhooks/{uuid}/deliveries/{delivery}/retry', [IntegrationController::class, 'retry'])->middleware('throttle:integration-retry');
});

Route::middleware(['admin', 'throttle:integration-settings'])->prefix('/api/admin/project-requests')->group(function (): void {
    Route::get('/', [AdminProjectRequestController::class, 'index']);
    Route::get('/count', [AdminProjectRequestController::class, 'count']);
    Route::get('/{uuid}', [AdminProjectRequestController::class, 'show']);
    Route::post('/{uuid}/approve', [AdminProjectRequestController::class, 'approve']);
    Route::post('/{uuid}/reject', [AdminProjectRequestController::class, 'reject']);
});

Route::middleware('admin')->prefix('/api/admin/projects')->group(function () {
    Route::get('/', [ProjectController::class, 'index']);
    Route::post('/', [ProjectController::class, 'store']);
    Route::get('/trash', [ProjectTrashController::class, 'index']);
    Route::post('/trash/confirmation', [ProjectTrashController::class, 'confirmDeletion'])->middleware('throttle:account-security')->block();
    Route::delete('/trash', [ProjectTrashController::class, 'empty'])->block();
    Route::post('/{projectUuid}/trash', [ProjectTrashController::class, 'trash']);
    Route::post('/{projectUuid}/restore', [ProjectTrashController::class, 'restore']);
    Route::post('/{projectUuid}/permanent/confirmation', [ProjectTrashController::class, 'confirmDeletion'])->middleware('throttle:account-security')->block();
    Route::delete('/{projectUuid}/permanent', [ProjectTrashController::class, 'destroy'])->block();
    Route::get('/{project}', [ProjectController::class, 'show']);
    Route::patch('/{project}', [ProjectController::class, 'update'])->middleware('project.permission:manage_project_settings');
});

Route::middleware('admin')->prefix('/api/admin/projects/{project}')->group(function () {
    Route::get('/feedback', [ProjectFeedbackController::class, 'review']);
    Route::post('/feedback/request', [ProjectFeedbackController::class, 'request']);
    Route::get('/team', [ProjectTeamController::class, 'index']);
    Route::post('/team/invitations', [ProjectTeamController::class, 'invite'])->middleware('throttle:invitation-accept');
    Route::post('/team/members', [ProjectTeamController::class, 'assign']);
    Route::delete('/team/members/{member}', [ProjectTeamController::class, 'remove']);
    Route::post('/ai/task-assistant', AiTaskAssistantController::class);
    Route::post('/export', ProjectExportController::class)->middleware('project.permission:export_project');
    Route::post('/export-data', BrowserProjectExportController::class)->middleware(['project.permission:export_project', 'throttle:browser-pdf']);
    Route::get('/team-chat', [TeamChatController::class, 'index']);
    Route::post('/team-chat', [TeamChatController::class, 'store'])->middleware('throttle:comments');
    Route::post('/team-chat/read', [TeamChatController::class, 'read']);
    Route::get('/team-chat/messages/{message}', [TeamChatController::class, 'show']);
    Route::get('/team-chat/files/{attachment}/{mode}', [TeamChatController::class, 'file']);
    Route::get('/discussions', [ProjectDiscussionController::class, 'index']);
    Route::post('/discussions', [ProjectDiscussionController::class, 'store'])->middleware('throttle:comments');
    Route::post('/discussions/read', [ProjectDiscussionController::class, 'read']);
    Route::get('/activity', [ProjectActivityController::class, 'show']);
    Route::post('/activity/read', [ProjectActivityController::class, 'read']);
    Route::get('/clients', [ProjectClientController::class, 'index'])->middleware('project.permission:manage_clients');
    Route::post('/clients', [ProjectClientController::class, 'store'])->middleware('project.permission:manage_clients');
    Route::post('/clients/{client}/invitation', [ProjectClientController::class, 'invite'])->middleware(['project.permission:manage_clients', 'throttle:account-security'])->block();
    Route::patch('/clients/{client}', [ProjectClientController::class, 'update'])->middleware('project.permission:manage_clients');
    Route::post('/clients/{client}/access-links', [AccessLinkController::class, 'store'])->middleware('project.permission:manage_clients');
    Route::delete('/clients/{client}/access-links/{token}', [AccessLinkController::class, 'destroy'])->middleware('project.permission:manage_clients');
    Route::get('/sections', [ProjectSectionController::class, 'index']);
    Route::post('/sections', [ProjectSectionController::class, 'store'])->middleware('project.permission:manage_structure');
    Route::patch('/sections/{section}', [ProjectSectionController::class, 'update'])->middleware('project.permission:manage_structure');
    Route::delete('/sections/{section}', [ProjectSectionController::class, 'destroy'])->middleware('project.permission:manage_structure');
});
Route::post('/api/client/project/{uuid}/login', [ClientProjectLoginController::class, 'store'])->whereUuid('uuid')->middleware('throttle:client-project-login');
Route::post('/client-sso/enter', [ClientSsoController::class, 'enter'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])->middleware('throttle:client-sso');
Route::get('/client-sso/complete', [ClientSsoController::class, 'complete'])->middleware('throttle:client-sso');
Route::get('/access/{token}', [AccessLinkController::class, 'enter'])->middleware('throttle:access')->name('client.access');
Route::middleware('client')->prefix('/api/client')->group(function () {
    Route::get('/project/{project}/sync', LiveSyncController::class)->middleware('throttle:live-sync');
    Route::get('/project/{project}/feedback', [ProjectFeedbackController::class, 'show']);
    Route::post('/project/{project}/feedback/invitation', [ProjectFeedbackController::class, 'invitation']);
    Route::post('/project/{project}/feedback', [ProjectFeedbackController::class, 'store'])->middleware('throttle:comments');
    Route::post('/project/{project}/ai/task-assistant', AiTaskAssistantController::class);
    Route::get('/notifications', [ClientNotificationController::class, 'show']);
    Route::patch('/notifications', [ClientNotificationController::class, 'update']);
    Route::post('/project/{project}/export', ProjectExportController::class);
    Route::post('/project/{project}/export-data', BrowserProjectExportController::class)->middleware('throttle:browser-pdf');
    Route::get('/project/{project}/discussions', [ProjectDiscussionController::class, 'index']);
    Route::post('/project/{project}/discussions', [ProjectDiscussionController::class, 'store'])->middleware('throttle:comments');
    Route::post('/project/{project}/discussions/read', [ProjectDiscussionController::class, 'read']);
    Route::get('/me', fn (Request $r) => response()->json(['id' => $r->attributes->get('project_client')->uuid, 'name' => $r->attributes->get('project_client')->name, 'email' => $r->attributes->get('project_client')->email, 'preferredLocale' => SupportedLocales::preference($r->attributes->get('project_client')->preferred_locale), 'project' => new ProjectResource($r->attributes->get('client_project'))]));
    Route::patch('/me/locale', [ClientLocaleController::class, 'update']);
    Route::get('/project', fn (Request $r) => new ProjectResource($r->attributes->get('client_project')));
    Route::get('/project/{project}', fn (Project $project) => new ProjectResource($project));
    Route::get('/project/{project}/sections', fn (Project $project) => response()->json(['data' => $project->briefSections->map(fn ($section) => ProjectStructure::sectionData($section))]));
});

Route::post('/api/invitations/resolve', [DeveloperInvitationController::class, 'show'])->middleware('throttle:invitation-resolve');
Route::post('/api/invitations/accept', [DeveloperInvitationController::class, 'accept'])->middleware('throttle:invitation-accept');
Route::middleware('admin')->prefix('/api/admin')->group(function () {
    Route::get('/account', [AccountController::class, 'show']);
    Route::patch('/account', [AccountController::class, 'update'])->middleware('throttle:profile-update');
    Route::post('/account/email', [AccountController::class, 'email'])->middleware('throttle:account-security');
    Route::post('/account/email/confirm', [AccountController::class, 'confirmEmail'])->middleware('throttle:account-security');
    Route::delete('/account/email', [AccountController::class, 'cancelEmail']);
    Route::post('/account/password', [AccountController::class, 'password'])->middleware('throttle:account-security');
    Route::post('/account/avatar', [AccountController::class, 'avatar'])->middleware('throttle:uploads');
    Route::get('/avatars/{key}', [AccountController::class, 'image']);

    Route::get('/projects/{project}/trash', [TaskTrashController::class, 'index'])->middleware('project.permission:manage_trash');
    Route::post('/tasks/{task}/restore', [TaskTrashController::class, 'restore']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
    Route::post('/tasks/{task}/place', [TaskOrderController::class, 'place']);
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
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
    Route::post('/tasks/{task}/approve', [TaskController::class, 'approve']);
});

Route::post('/api/admin/tasks/{task}/comments', [CommentController::class, 'store'])->middleware(['admin', 'throttle:comments']);
Route::post('/api/client/tasks/{task}/comments', [CommentController::class, 'store'])->middleware(['client', 'throttle:comments']);

foreach (['admin', 'client'] as $actor) {
    Route::middleware($actor)->prefix('/api/'.$actor)->group(function () {
        Route::post('/tasks/{task}/attachments', [AttachmentController::class, 'store'])->middleware('throttle:uploads');
        Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download']);
        Route::get('/attachments/{attachment}/original', [AttachmentController::class, 'original']);
        Route::get('/attachments/{attachment}/preview', [AttachmentController::class, 'preview']);
    });
}

foreach (['admin', 'client'] as $actor) {
    Route::middleware($actor)->prefix('/api/'.$actor.'/push')->group(function () {
        Route::get('/status', [PushSubscriptionController::class, 'status']);
        Route::post('/subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:push-subscribe');
        Route::delete('/subscriptions', [PushSubscriptionController::class, 'destroy']);
    });
}
Route::middleware(['admin', 'project.permission:manage_project_notifications'])->prefix('/api/admin/projects/{project}/notifications')->group(function () {
    Route::get('/', [NotificationSettingsController::class, 'show']);
    Route::patch('/', [NotificationSettingsController::class, 'update']);
    Route::post('/test', [NotificationSettingsController::class, 'test'])->middleware('throttle:project-notification-test');
});

foreach (['admin', 'client'] as $actor) {
    Route::middleware($actor)->prefix('/api/'.$actor.'/telegram')->group(function (): void {
        Route::get('/', [TelegramController::class, 'show']);
        Route::post('/connect', [TelegramController::class, 'connect'])->middleware('throttle:telegram-connect');
        Route::delete('/', [TelegramController::class, 'disconnect']);
        Route::post('/test', [TelegramController::class, 'test'])->middleware('throttle:telegram-test');
    });
}
Route::post('/api/telegram/webhook', [TelegramController::class, 'webhook'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:telegram-webhook');

Route::middleware('admin')->prefix('/api/admin/workspaces/{brandingWorkspace}/branding')->controller(WorkspaceBrandingController::class)->group(function (): void {
    Route::get('/', 'show');
    Route::patch('/', 'update');
    Route::post('/sanitize-svg', 'sanitize')->middleware('throttle:uploads');
    Route::post('/assets/{kind}', 'asset')->middleware('throttle:uploads');
    Route::delete('/assets/{kind}', 'asset');
    Route::post('/reset', 'reset');
});
Route::get('/branding-assets/{workspace}/{token}.{extension}', [WorkspaceBrandingController::class, 'image'])->where('token', '[a-f0-9]{48}')->whereIn('extension', ['png', 'svg'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);
Route::any('/{fallbackPlaceholder}', SpaController::class)->where('fallbackPlaceholder', '.*')->fallback();
