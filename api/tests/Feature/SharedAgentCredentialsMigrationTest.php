<?php

namespace Tests\Feature;

use App\Models\ExternalAgent;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedAgentCredentialsMigrationTest extends TestCase
{
    public function test_upgrade_preserves_individual_tokens_audit_and_grants_without_creating_shared_links(): void
    {
        $original = config('database.default');
        config(['database.connections.shared_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'database.default' => 'shared_upgrade']);
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (str_contains($path, 'add_shared_agent_credentials')) {
                    $migration = require $path;
                } else {
                    (require $path)->up();
                }
            }
            $project = Project::factory()->create();
            $agent = ExternalAgent::create(['workspace_id' => $project->workspace_id, 'name' => 'Historical executor', 'kind' => 'ai', 'active' => true]);
            $id = DB::table('integration_tokens')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'external_agent_id' => $agent->id, 'name' => 'Old key', 'scopes' => json_encode(['agent:profile']), 'secret_hash' => hash('sha256', 'synthetic-old-key'), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('agent_operations')->insert(['workspace_id' => $project->workspace_id, 'external_agent_id' => $agent->id, 'integration_token_id' => $id, 'operation' => 'GET /api/v1/agent/me', 'http_status' => 200, 'created_at' => now()]);
            $token = (array) DB::table('integration_tokens')->find($id);
            $operation = (array) DB::table('agent_operations')->first();
            $migration->up();
            $migration->up();
            $current = (array) DB::table('integration_tokens')->find($id);
            $this->assertFalse((bool) $current['agent_shared']);
            unset($current['agent_shared']);
            $this->assertSame($token, $current);
            $audit = (array) DB::table('agent_operations')->first();
            $this->assertNull($audit['claimed_agent_uuid']);
            unset($audit['claimed_agent_uuid']);
            $this->assertSame($operation, $audit);
            $this->assertSame(0, DB::table('agent_credential_links')->count());
            $this->assertSame(0, DB::table('agent_project_grants')->count());
        } finally {
            config(['database.default' => $original]);
            DB::purge('shared_upgrade');
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
