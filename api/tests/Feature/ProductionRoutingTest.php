<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductionRoutingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ?string $publicDirectory = null;

    protected function tearDown(): void
    {
        if ($this->publicDirectory !== null) {
            File::deleteDirectory($this->publicDirectory);
        }
        parent::tearDown();
    }

    private function installSpaFixture(): void
    {
        $this->publicDirectory = sys_get_temp_dir().'/project-brief-spa-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->publicDirectory);
        File::put($this->publicDirectory.'/index.html', '<!doctype html><div id="app">SPA fixture</div>');
        $this->app->usePublicPath($this->publicDirectory);
    }

    public function test_health_returns_only_liveness_without_starting_database_session(): void
    {
        config(['session.driver' => 'database', 'database.default' => 'mysql', 'database.connections.mysql.host' => 'unreachable.invalid']);

        $this->getJson('/api/health')->assertOk()->assertExactJson(['ok' => true])->assertHeader('Cache-Control', 'no-store, private');
    }

    #[TestWith(['/'])]
    #[TestWith(['/admin/login'])]
    #[TestWith(['/admin/projects/example/brief'])]
    #[TestWith(['/project/example/task/example'])]
    public function test_serves_built_spa_on_direct_navigation(string $path): void
    {
        $this->installSpaFixture();

        $response = $this->get($path);

        $response->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertSame('<!doctype html><div id="app">SPA fixture</div>', $response->baseResponse->getFile()->getContent());
    }

    #[TestWith(['/api/unknown'])]
    #[TestWith(['/access/unknown/extra'])]
    #[TestWith(['/sanctum/csrf-cookie'])]
    #[TestWith(['/api'])]
    public function test_reserved_backend_paths_never_receive_spa(string $path): void
    {
        $this->installSpaFixture();

        $this->getJson($path)->assertNotFound()->assertDontSee('SPA fixture');
    }

    public function test_unbuilt_local_backend_keeps_service_response(): void
    {
        $this->getJson('/')->assertOk()->assertExactJson(['service' => 'ProjectBrief API']);
    }

    public function test_unknown_local_route_without_spa_returns_404(): void
    {
        $this->getJson('/unknown')->assertNotFound();
    }

    public function test_spa_does_not_accept_unknown_mutation(): void
    {
        $this->installSpaFixture();

        $this->postJson('/unknown')->assertNotFound()->assertDontSee('SPA fixture');
    }
}
