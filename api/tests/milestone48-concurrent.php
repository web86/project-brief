<?php

use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\DeveloperInvitations;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'projectbrief_m48_qa') {
    throw new RuntimeException('Dedicated MariaDB QA database required.');
}
$mode = $argv[1] ?? '';
$token = getenv('M48_INVITATION_TOKEN');
if ($mode === 'prepare') {
    $project = Project::factory()->create();
    $owner = User::factory()->owner($project->workspace)->create();
    $email = 'race-'.bin2hex(random_bytes(8)).'@example.test';
    if (($argv[2] ?? '') === 'existing') {
        User::factory()->create(['email' => $email]);
    }
    DeveloperInvitation::create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'email' => $email, 'project_ids' => [$project->id], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);
    echo json_encode(['project' => $project->id, 'email' => $email]);
} elseif ($mode === 'accept') {
    echo "ready\n";
    fflush(STDOUT);
    fgets(STDIN);
    $request = Request::create('/api/invitations/accept', 'POST');
    $request->setUserResolver(fn () => User::where('email', getenv('M48_RACE_EMAIL'))->first());
    try {
        app(DeveloperInvitations::class)->accept($request, ['token' => $token, 'name' => 'Concurrent developer', 'password' => 'Concurrent-password!', 'password_confirmation' => 'Concurrent-password!']);
        echo json_encode(['status' => 200]);
    } catch (HttpException $e) {
        echo json_encode(['status' => $e->getStatusCode()]);
    } catch (Throwable $e) {
        echo json_encode(['error' => get_class($e)]);
        exit(1);
    }
} elseif ($mode === 'inspect') {
    $user = User::where('email', getenv('M48_RACE_EMAIL'))->firstOrFail();
    echo json_encode(['users' => User::where('email', $user->email)->count(), 'memberships' => $user->workspaceMembers()->count(), 'projects' => ProjectMember::where('project_id', $argv[2])->count()]);
}
