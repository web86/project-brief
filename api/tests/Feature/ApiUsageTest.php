<?php

namespace Tests\Feature;

use App\Http\Controllers\IntegrationApiController;
use App\Http\Middleware\MeasureApiUsage;
use App\Models\Workspace;
use App\Services\ApiUsage;
use App\Services\PlatformOverview;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiUsageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Transactional feature fixtures use their transaction's connection. The real
        // independent MariaDB connection is exercised by platform-mariadb.php workers.
        $this->app->bind(ApiUsage::class, fn () => new class extends ApiUsage
        {
            public function connection(): Connection
            {
                return DB::connection();
            }
        });
    }

    private function token(): array
    {
        $workspace = Workspace::factory()->create();
        $source = DB::table('integration_sources')->insertGetId(['uuid' => Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'Source', 'created_at' => now(), 'updated_at' => now()]);
        $secret = 'pb_'.bin2hex(random_bytes(32));
        $id = DB::table('integration_tokens')->insertGetId(['uuid' => Str::uuid(), 'workspace_id' => $workspace->id, 'integration_source_id' => $source, 'name' => 'Token', 'secret_hash' => hash('sha256', $secret), 'scopes' => '["projects:read"]', 'created_at' => now(), 'updated_at' => now()]);

        return [$secret, $id, $workspace, $source];
    }

    public function test_final_401_403_429_500_statuses_and_route_templates_are_recorded_without_sensitive_data(): void
    {
        [$secret, $id, $workspace, $source] = $this->token();
        $this->getJson('/api/v1/projects?email=DO-NOT-STORE')->assertUnauthorized();
        $this->withToken($secret)->getJson('/api/v1/projects')->assertOk();
        $this->withToken($secret)->getJson('/api/v1/projects/'.Str::uuid().'/tasks?token=DO-NOT-STORE')->assertForbidden();
        Route::getRoutes()->getByAction(IntegrationApiController::class.'@projects')->uses(fn () => throw new \RuntimeException('DO-NOT-STORE'));
        $this->withToken($secret)->getJson('/api/v1/projects')->assertStatus(500);
        RateLimiter::increment('integration-workspace:'.$workspace->id, 60, 60);
        $this->withToken($secret)->getJson('/api/v1/projects')->assertTooManyRequests();
        $rows = DB::table('api_usage_hours')->get();
        $this->assertEquals(5, $rows->sum('requests'));
        $this->assertEquals(1, $rows->sum('responses_2xx'));
        $this->assertEquals(3, $rows->sum('responses_4xx'));
        $this->assertEquals(1, $rows->sum('responses_429'));
        $this->assertEquals(1, $rows->sum('responses_5xx'));
        $this->assertDatabaseHas('api_usage_hours', ['workspace_id' => $workspace->id, 'integration_source_id' => $source, 'integration_token_id' => $id, 'route' => '/api/v1/projects/{uuid}/tasks']);
        $this->assertDatabaseHas('api_usage_hours', ['workspace_id' => null, 'integration_token_id' => null]);
        foreach (['DO-NOT-STORE', $secret, $workspace->uuid, 'Authorization', 'email='] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $rows->toJson());
        }
    }

    public function test_atomic_updates_preserve_totals_maximum_and_weighted_average_per_hour(): void
    {
        $this->freezeTime();
        $request = Request::create('/api/v1/projects/secret?token=secret');
        $request->setRouteResolver(fn () => new \Illuminate\Routing\Route('GET', 'api/v1/projects/{uuid}', fn () => null));
        $service = app(ApiUsage::class);
        foreach ([[200, 1000], [201, 3000], [403, 8000], [429, 4000], [500, 9000]] as [$status, $duration]) {
            $service->record($request, $status, $duration);
        }
        $this->assertDatabaseCount('api_usage_hours', 1);
        $row = DB::table('api_usage_hours')->first();
        $this->assertEquals(5, $row->requests);
        $this->assertEquals(25000, $row->duration_us);
        $this->assertEquals(9000, $row->max_duration_us);
        $summary = app(PlatformOverview::class)->usage(app(PlatformOverview::class)->since('24h'))->first();
        $this->assertEquals(5, $summary->average_ms);
        $this->assertEquals(9, $summary->maximum_ms);
        $this->travel(1)->hours();
        $service->record($request, 200, 1000);
        $this->assertDatabaseCount('api_usage_hours', 2);
    }

    public function test_telemetry_failure_and_disabled_metrics_never_change_api_result(): void
    {
        $this->mock(ApiUsage::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('database unavailable'));
        $this->getJson('/api/v1/projects')->assertUnauthorized()->assertJsonPath('code', 'invalid_token');
        config(['platform.metrics_enabled' => false]);
        $this->getJson('/api/v1/projects')->assertUnauthorized();
        $this->assertDatabaseCount('api_usage_hours', 0);
    }

    public function test_unknown_routes_and_methods_have_bounded_cardinality_and_no_guessed_tenant(): void
    {
        $this->getJson('/api/v1/unknown/secret-a?email=secret')->assertUnauthorized();
        $this->getJson('/api/v1/unknown/secret-b')->assertUnauthorized();
        $row = DB::table('api_usage_hours')->sole();
        $this->assertSame('/api/v1/{unmatched}', $row->route);
        $this->assertNull($row->workspace_id);
        $this->assertEquals(2, $row->requests);
        $this->getJson('/api/health')->assertOk();
        $this->assertEquals(2, DB::table('api_usage_hours')->sum('requests'));
    }

    public function test_terminating_twice_does_not_double_count(): void
    {
        $request = Request::create('/api/v1/projects');
        $middleware = app(MeasureApiUsage::class);
        $response = $middleware->handle($request, fn () => response()->json([], 403));
        $middleware->terminate($request, $response);
        $middleware->terminate($request, $response);
        $this->assertEquals(1, DB::table('api_usage_hours')->sum('requests'));
    }
}
