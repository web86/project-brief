<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\DemoDatasetAudit;
use App\Services\DemoEnvironment;
use App\Services\DemoStaticSnapshotExport;
use App\Services\ProjectStructure;
use Database\Seeders\ProfessionalDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfessionalDemoTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_static_export_fails_closed_outside_an_enabled_isolated_demo(): void
    {
        config(['demo.enabled' => false]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('explicitly enabled isolated');
        app(DemoStaticSnapshotExport::class)->run();
    }

    public function test_static_export_rejects_test_fixture_or_writer_connections(): void
    {
        config(['demo.test_fixture' => true, 'demo.enabled' => true, 'demo.read_only' => false]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SELECT-only demo_reader');
        app(DemoStaticSnapshotExport::class)->run();
    }

    private function demo(): void
    {
        config(['demo.test_fixture' => true, 'demo.enabled' => true, 'demo.read_only' => false, 'demo.password' => bin2hex(random_bytes(20))]);
        $this->seed(ProfessionalDemoSeeder::class);
        config(['demo.read_only' => true]);
        $this->withCredentials();
    }

    public function test_dataset_is_coherent_and_public_demo_is_read_only_for_both_personas(): void
    {
        $this->demo();
        $audit = app(DemoDatasetAudit::class)->run();
        $this->assertSame(150, $audit['counts']['tasks']);
        $this->assertSame(0, $audit['orphans']);
        $this->getJson('/api/demo/enter/developer')->assertOk()->assertJsonPath('destination', '/admin');
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('user.platformAdmin', false)->assertJsonPath('user.membershipRole', 'owner');
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonCount(10, 'data');
        $project = Project::where('title', 'Northstar Commerce')->firstOrFail();
        $this->assertSame('Core experience', $project->briefSections()->first()->name);
        $task = $project->tasks()->orderBy('id')->skip(2)->first();
        $this->assertStringStartsWith('1.', ProjectStructure::number($task));
        $this->getJson('/api/admin/tasks/'.$task->uuid)->assertOk()->assertJsonPath('data.hasAgentHistory', true)->assertJsonPath('data.agentAssignments.0.agent.hasCredentials', true);
        $this->getJson('/api/admin/integrations')->assertOk()->assertJsonPath('tokens.0.synthetic', true);
        $this->assertSame(4, DB::table('integration_tokens')->where('expires_at', '<', now())->count());
        $this->getJson('/api/platform/overview')->assertForbidden();
        $before = $this->snapshot();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, '/api/admin/tasks/'.$task->uuid, ['title' => 'Must not change'])->assertForbidden()->assertJsonPath('code', 'demo_view_only');
            $this->json($method, '/api/v1/projects', ['title' => 'Blocked'], ['Authorization' => 'Bearer manually-provided'])->assertForbidden();
        }
        foreach (['/api/security/passkey/register/options', '/api/security/passkey/login/options', '/access/synthetic-token', '/client-sso/complete?code=synthetic', '/api/admin/account', '/api/platform/plans'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->postJson('/api/admin/projects/'.$project->uuid.'/activity/read')->assertForbidden();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/export-data')->assertForbidden();
        $this->getJson('/api/demo/enter/client')->assertOk();
        $this->getJson('/api/client/project/'.$project->uuid)->assertOk()->assertJsonMissingPath('data.agents');
        $response = $this->getJson('/api/client/tasks/'.$task->uuid)->assertOk()->assertJsonMissingPath('data.price')->assertJsonMissingPath('data.estimateHours')->assertJsonMissingPath('data.developerNotes')->assertJsonMissingPath('data.agentAssignments');
        foreach (['agent_', 'agentId', 'agentName', 'agentState', 'Frontend Implementation Agent', 'agentSource', 'synthetic'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $foreign = Project::where('title', 'Lumen Health')->firstOrFail();
        $this->getJson('/api/client/project/'.$foreign->uuid)->assertNotFound();
        $this->getJson('/api/client/tasks/'.$foreign->tasks()->first()->uuid)->assertNotFound();
        $this->getJson('/api/admin/integrations')->assertUnauthorized();
        $this->postJson('/api/client/tasks/'.$task->uuid.'/comments', ['text' => 'Blocked'])->assertForbidden();
        $this->assertSame($before, $this->snapshot());
        config(['demo.enabled' => false]);
    }

    public function test_http_read_boundary_rejects_a_future_get_handler_that_attempts_a_database_write(): void
    {
        $this->demo();
        Route::get('/api/demo/status', function () {
            DB::table('projects')->update(['title' => 'Illegal side effect']);

            return response()->json(['ok' => true]);
        });
        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Demo database is read only.');
        $this->getJson('/api/demo/status');
    }

    public function test_demo_is_off_by_default_and_production_cannot_be_selected_for_seeding(): void
    {
        $this->getJson('/api/demo/status')->assertNotFound();
        config(['demo.enabled' => true]);
        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        DemoEnvironment::assertIsolated();
    }

    private function snapshot(): string
    {
        $data = [];
        foreach (['projects', 'tasks', 'task_history', 'comments', 'comment_reads', 'client_comment_reads', 'project_reads', 'agent_runs', 'integration_tokens', 'webhook_deliveries', 'notification_deliveries', 'account_security_challenges'] as $table) {
            $data[$table] = DB::table($table)->get()->toArray();
        }

        return hash('sha256', json_encode($data));
    }
}
