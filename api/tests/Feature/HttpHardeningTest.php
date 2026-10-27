<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountSecurityDiagnostics;
use App\Services\DocxProjectExporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class HttpHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDirectory = sys_get_temp_dir().'/project-brief-hardening-'.bin2hex(random_bytes(8));
        config(['cache.stores.file.path' => $this->cacheDirectory, 'cache.stores.file.lock_path' => $this->cacheDirectory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cacheDirectory);
        parent::tearDown();
    }

    #[TestWith([401])]
    #[TestWith([403])]
    #[TestWith([404])]
    #[TestWith([413])]
    #[TestWith([419])]
    #[TestWith([422])]
    #[TestWith([423])]
    #[TestWith([429])]
    #[TestWith([500])]
    public function test_final_exception_responses_have_private_headers(int $status): void
    {
        Route::get('/api/hardening-fixture', fn () => abort($status));
        $this->getJson('/api/hardening-fixture')->assertStatus($status)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_dynamic_success_and_stream_headers_preserve_content_and_sandbox(): void
    {
        $this->getJson('/api/csrf')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        Route::get('/api/stream-fixture', fn () => response()->streamDownload(fn () => print 'synthetic-content', 'fixture.txt', ['Content-Security-Policy' => "default-src 'none'; sandbox"]));
        $response = $this->get('/api/stream-fixture')->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('fixture.txt', $response->headers->get('Content-Disposition'));
        $this->assertSame('synthetic-content', $response->streamedContent());
    }

    public function test_bootstrap_is_disabled_by_default_and_health_is_never_in_the_budget(): void
    {
        $this->assertFalse(config('http_security.bootstrap_enabled'));
        config(['http_security.bootstrap_per_minute' => 1]);
        $this->getJson('/api/session')->assertOk();
        $this->getJson('/api/session')->assertOk();
        config(['http_security.bootstrap_enabled' => true]);
        $this->getJson('/api/session')->assertOk();
        $this->getJson('/api/session')->assertTooManyRequests()->assertHeader('Retry-After');
        $this->getJson('/api/health')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_enabled_bootstrap_rejects_before_session_or_database_and_ignores_forwarded_ip(): void
    {
        config(['http_security.bootstrap_enabled' => true, 'http_security.bootstrap_per_minute' => 1]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.73'])->getJson('/api/csrf')->assertOk();
        config(['session.driver' => 'database', 'database.default' => 'mysql', 'database.connections.mysql.host' => 'unreachable.invalid']);
        $response = $this->withHeader('X-Forwarded-For', '203.0.113.9')->head('/api/session');
        $response->assertTooManyRequests()->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_twenty_people_share_two_bootstraps_per_minute_and_recover_after_window(): void
    {
        config(['http_security.bootstrap_enabled' => true, 'http_security.bootstrap_per_minute' => 120]);
        for ($round = 0; $round < 2; $round++) {
            for ($person = 0; $person < 20; $person++) {
                $this->app['session.store']->invalidate();
                foreach (['/api/csrf', '/api/session', '/api/security/status'] as $url) {
                    $this->getJson($url)->assertOk();
                }
            }
        }
        $this->getJson('/api/session')->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->getJson('/api/session')->assertOk();
    }

    #[TestWith(['ru'])]
    #[TestWith(['en'])]
    #[TestWith(['tr'])]
    #[TestWith(['zh-CN'])]
    public function test_early_bootstrap_refusal_localizes_without_opening_a_session(string $locale): void
    {
        config(['http_security.bootstrap_enabled' => true, 'http_security.bootstrap_per_minute' => 1]);
        $this->withHeader('Accept-Language', $locale)->getJson('/api/session')->assertOk();
        $this->getJson('/api/session')->assertTooManyRequests()->assertJsonPath('message', trans('http_security.rate_limited', [], $locale));
    }

    public function test_different_guest_actions_no_longer_compete_for_a_numeric_bucket(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/invitations/resolve', [])->assertUnprocessable();
        }
        $this->postJson('/api/invitations/resolve', [])->assertTooManyRequests();
        $this->postJson('/api/invitations/accept', [])->assertUnprocessable();
    }

    public function test_authenticated_profile_and_notification_actions_have_independent_budgets(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        for ($i = 0; $i < 6; $i++) {
            $this->patchJson('/api/admin/account', ['name' => 'Synthetic owner', 'preferredLocale' => 'en'])->assertOk();
        }
        $this->postJson('/api/admin/notifications/test', [])->assertStatus(422);
    }

    private function project(): Project
    {
        $project = Project::factory()->create();
        Task::factory()->for($project)->create(['project_section_id' => $project->briefSections->first()->id]);
        $this->actingAs(User::factory()->owner($project->workspace)->create());

        return $project;
    }

    public function test_named_client_actions_use_verified_client_identity_instead_of_a_shared_nat(): void
    {
        $project = $this->project();
        for ($actor = 0; $actor < 2; $actor++) {
            $client = $project->clients()->create(['name' => 'Push actor '.$actor, 'active' => true]);
            $token = bin2hex(random_bytes(32));
            $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $token)]);
            $this->get('/access/'.$token)->assertRedirect();
            for ($i = 0; $i < 30; $i++) {
                $this->postJson('/api/client/push/subscriptions', [])->assertUnprocessable();
            }
            $this->postJson('/api/client/push/subscriptions', [])->assertTooManyRequests();
        }
    }

    private function payload(): array
    {
        return ['format' => 'docx', 'scope' => 'all', 'locale' => 'en'];
    }

    public function test_docx_actor_budget_prevents_a_sixth_render_and_preserves_authorization(): void
    {
        $project = $this->project();
        $this->mock(DocxProjectExporter::class)->shouldReceive('render')->times(5)->andReturn('synthetic-docx');
        $url = '/api/admin/projects/'.$project->uuid.'/export';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($url, $this->payload())->assertOk();
        }
        $this->postJson($url, $this->payload())->assertTooManyRequests()->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
        $other = Project::factory()->create();
        $this->postJson('/api/admin/projects/'.$other->uuid.'/export', $this->payload())->assertNotFound();
        $this->travel(61)->seconds();
        $this->mock(DocxProjectExporter::class)->shouldReceive('render')->once()->andReturn('synthetic-docx');
        $this->postJson($url, $this->payload())->assertOk();
    }

    public function test_docx_workspace_budget_is_shared_by_distinct_clients_and_not_other_workspaces(): void
    {
        $project = $this->project();
        $this->mock(DocxProjectExporter::class)->shouldReceive('render')->times(10)->andReturn('synthetic-docx');
        for ($actor = 0; $actor < 3; $actor++) {
            $client = $project->clients()->create(['name' => 'Synthetic client '.$actor, 'active' => true]);
            $token = bin2hex(random_bytes(32));
            $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $token)]);
            $this->get('/access/'.$token)->assertRedirect();
            for ($i = 0; $i < ($actor < 2 ? 5 : 1); $i++) {
                $response = $this->postJson('/api/client/project/'.$project->uuid.'/export', $this->payload());
                $actor < 2 ? $response->assertOk() : $response->assertTooManyRequests();
            }
        }
        $other = $this->project();
        $this->mock(DocxProjectExporter::class)->shouldReceive('render')->once()->andReturn('synthetic-docx');
        $this->postJson('/api/admin/projects/'.$other->uuid.'/export', $this->payload())->assertOk();
    }

    #[TestWith(['count'])]
    #[TestWith(['text'])]
    public function test_docx_size_rejection_happens_before_task_materialization_or_render(string $kind): void
    {
        $project = $this->project();
        config(['browser_pdf.max_tasks' => 2, 'browser_pdf.max_text_bytes' => 100]);
        Task::factory()->for($project)->count($kind === 'count' ? 3 : 1)->create(['project_section_id' => $project->briefSections->first()->id, 'title' => str_repeat('界', 40)]);
        $retrieved = 0;
        Event::listen('eloquent.retrieved: '.Task::class, function () use (&$retrieved): void {
            $retrieved++;
        });
        $this->mock(DocxProjectExporter::class)->shouldNotReceive('render');
        $this->postJson('/api/admin/projects/'.$project->uuid.'/export', $this->payload())->assertStatus(413)->assertSee(trans('exports.tooLarge', [], 'en'));
        $this->assertSame(0, $retrieved);
    }

    public function test_expected_security_failures_are_sampled_but_server_failures_are_not(): void
    {
        Log::spy();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/security/passkey/login', [])->assertUnprocessable();
        }
        Log::shouldHaveReceived('warning')->once();
        $this->travel(61)->seconds();
        $this->postJson('/api/security/passkey/login', [])->assertUnprocessable();
        Log::shouldHaveReceived('warning')->twice();
        for ($i = 0; $i < 2; $i++) {
            app(AccountSecurityDiagnostics::class)->render(new \RuntimeException('SYNTHETIC_PRIVATE_SECRET'), Request::create('/api/security/status'))->getStatusCode();
        }
        Log::shouldHaveReceived('warning')->times(4)->withArgs(fn ($event, $context) => ! str_contains(json_encode($context), 'SYNTHETIC_PRIVATE_SECRET'));
    }

    public function test_generic_sql_failure_logs_only_safe_metadata_and_masks_http_errors(): void
    {
        config(['app.debug' => false]);
        Log::spy();
        Route::get('/api/sql-fixture', fn () => throw new QueryException('sqlite', 'select SYNTHETIC_PRIVATE_SQL', ['SYNTHETIC_PRIVATE_SECRET'], new \PDOException('SYNTHETIC_PRIVATE_DRIVER')));
        $this->getJson('/api/sql-fixture?token=SYNTHETIC_PRIVATE_QUERY')->assertStatus(500)->assertDontSee('SYNTHETIC_PRIVATE')->assertHeader('Cache-Control', 'no-store, private');
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($event, $context) => $event === 'application_failure' && ! str_contains(json_encode($context), 'SYNTHETIC_PRIVATE'));
    }

    public function test_daily_handler_creates_a_private_rotating_file(): void
    {
        config(['logging.channels.daily.path' => $this->cacheDirectory.'/laravel.log']);
        Log::channel('daily')->error('synthetic_retention_check');
        $files = File::glob($this->cacheDirectory.'/laravel-*.log');
        $this->assertCount(1, $files);
        $this->assertSame(0600, fileperms($files[0]) & 0777);
        $this->assertStringContainsString('synthetic_retention_check', file_get_contents($files[0]));
    }
}
