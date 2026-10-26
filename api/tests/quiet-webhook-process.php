<?php

use App\Models\Project;
use App\Services\WebhookDestination;
use App\Services\WebhookEvents;
use App\Services\WebhookProcessor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'quiet-webhook-test-')) {
    throw new RuntimeException('Disposable cron test database required.');
}
$mode = $argv[1];
if ($mode === 'seed') {
    $project = Project::factory()->create();
    foreach (['bad', 'good'] as $name) {
        DB::table('webhook_endpoints')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'name' => $name, 'url' => 'https://'.$name.'.example.com/PRIVATE_URL?token=PRIVATE_SECRET', 'events' => json_encode(['project.updated']), 'enabled' => true, 'secret' => Crypt::encryptString('PRIVATE_SIGNING_SECRET'), 'secret_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    app(WebhookEvents::class)->project($project, 'project.updated', ['title' => 'PRIVATE_BODY']);
    exit;
}
if ($mode === 'stats') {
    echo json_encode(['statuses' => DB::table('webhook_deliveries')->orderBy('id')->pluck('status'), 'attempts' => Schema::hasTable('webhook_attempts') ? DB::table('webhook_attempts')->count() : null]);
    exit;
}
if ($mode === 'due') {
    DB::table('webhook_deliveries')->where('status', 'pending')->update(['available_at' => now()->subSecond()]);
    exit;
}
if ($mode === 'busy') {
    DB::table('integration_locks')->upsert([['name' => 'webhooks', 'owner' => 'other', 'expires_at' => now()->addSeconds(90)]], ['name'], ['owner', 'expires_at']);
    exit;
}
if ($mode === 'db-error') {
    Schema::drop('webhook_attempts');
}
if ($mode === 'lock-error') {
    Schema::drop('integration_locks');
}
if (in_array($mode, ['exception', 'fatal'], true)) {
    $app->instance(WebhookProcessor::class, new class($mode) extends WebhookProcessor
    {
        public function __construct(private string $mode) {}

        public function process(int $limit = 20, int $seconds = 45, ?Closure $report = null): int
        {
            if ($this->mode === 'fatal') {
                throw new Error('PRIVATE_SECRET fatal runtime error');
            }
            throw new RuntimeException('PRIVATE_SECRET stack/body/headers');
        }
    });
}
$app->instance(WebhookDestination::class, new class($mode) extends WebhookDestination
{
    public function __construct(private string $mode) {}

    public function resolve(string $url): array
    {
        if ($this->mode === 'unsafe') {
            throw ValidationException::withMessages(['url' => 'PRIVATE_URL']);
        }

        return ['host' => 'example.com', 'ip' => '93.184.216.34'];
    }
});
Http::preventStrayRequests();
Http::fake(function ($request) use ($mode) {
    if ($mode === 'transport') {
        throw new ConnectionException('PRIVATE_URL PRIVATE_SECRET timeout');
    }

    return Http::response('PRIVATE_RESPONSE', str_contains($request->url(), 'bad.') ? match ($mode) {
        'mixed' => 400, 'retry' => 503, 'redirect' => 302, default => 204,
    } : 204);
});
$input = new ArgvInput(['artisan', 'integrations:webhooks', ...array_slice($argv, 2)]);
$output = new ConsoleOutput;
$status = $kernel->handle($input, $output);
$kernel->terminate($input, $status);
exit($status);
