<?php

use App\Http\Middleware\MeasureApiUsage;
use App\Services\ApiUsage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'projectbrief_m90_qa') {
    throw new RuntimeException('Dedicated disposable MariaDB required.');
}
function sample(): Request
{
    $request = Request::create('/api/v1/projects/private-value?token=PRIVATE');
    $request->setRouteResolver(fn () => new Route('GET', 'api/v1/projects/{uuid}', fn () => null));
    $request->attributes->set('usage.hour', now('UTC')->startOfHour()->format('Y-m-d H:i:s'));

    return $request;
}
$service = app(ApiUsage::class);
if ($argv[1] === 'prepare') {
    DB::table('api_usage_hours')->delete();
    echo json_encode(['php' => PHP_VERSION, 'database' => DB::selectOne('SELECT VERSION() as version')->version]);
} elseif ($argv[1] === 'worker') {
    $request = sample();
    $service->connection()->getPdo();
    echo "ready\n";
    fgets(STDIN);
    $times = [];
    for ($i = 0; $i < 200; $i++) {
        $start = hrtime(true);
        $service->record($request, [200, 403, 429, 500][$i % 4], ($i + 1) * 1000);
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    echo json_encode($times);
} elseif ($argv[1] === 'inspect') {
    echo json_encode(DB::table('api_usage_hours')->get());
} elseif ($argv[1] === 'benchmark') {
    $request = sample();
    $queryCount = 0;
    $service->connection()->listen(function ($event) use (&$queryCount): void {
        if (str_contains($event->sql, 'api_usage_hours')) {
            $queryCount++;
        }
    });
    $start = hrtime(true);
    $service->record($request, 200, 1000);
    $cold = (hrtime(true) - $start) / 1e6;
    $times = [];
    for ($i = 0; $i < 200; $i++) {
        $start = hrtime(true);
        $service->record($request, 200, 1000);
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);
    echo json_encode(['cold_ms' => $cold, 'p50_ms' => $times[99], 'p95_ms' => $times[189], 'p99_ms' => $times[197], 'sql_statements' => $queryCount, 'writes' => 201, 'lock_wait_seconds' => $service->connection()->selectOne('SELECT @@innodb_lock_wait_timeout as seconds')->seconds]);
} elseif ($argv[1] === 'http') {
    DB::table('api_usage_hours')->delete();
    $workspace = DB::table('workspaces')->insertGetId(['uuid' => Str::uuid(), 'name' => 'QA', 'created_at' => now(), 'updated_at' => now()]);
    $secret = 'pb_'.bin2hex(random_bytes(32));
    DB::table('integration_tokens')->insert(['uuid' => Str::uuid(), 'workspace_id' => $workspace, 'name' => 'QA', 'secret_hash' => hash('sha256', $secret), 'scopes' => '["projects:read"]', 'created_at' => now(), 'updated_at' => now()]);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $measurements = [];
    foreach ([false, true, false, true] as $enabled) {
        config(['platform.metrics_enabled' => $enabled]);
        $times = [];
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::clear('integration-workspace:'.$workspace);
            RateLimiter::clear('integration-ip:127.0.0.1');
            $request = Request::create('/api/v1/projects', 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'REMOTE_ADDR' => '127.0.0.1']);
            $start = hrtime(true);
            $response = $kernel->handle($request);
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException($response->getContent());
            }
            $handled = (hrtime(true) - $start) / 1e6;
            $kernel->terminate($request, $response);
            $times[] = ['handled_ms' => $handled, 'total_ms' => (hrtime(true) - $start) / 1e6];
        }
        $measurements[] = ['enabled' => $enabled, 'samples' => $times];
    }
    echo json_encode(['measurements' => $measurements, 'recorded_requests' => DB::table('api_usage_hours')->sum('requests')]);
} elseif ($argv[1] === 'lock') {
    $service->record(sample(), 200, 1000);
    DB::beginTransaction();
    DB::table('api_usage_hours')->update(['requests' => DB::raw('requests + 1')]);
    echo "ready\n";
    fgets(STDIN);
    DB::rollBack();
} elseif ($argv[1] === 'failure') {
    $middleware = new MeasureApiUsage;
    $request = sample();
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));
    $start = hrtime(true);
    $middleware->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent(), 'terminate_ms' => (hrtime(true) - $start) / 1e6]);
}
