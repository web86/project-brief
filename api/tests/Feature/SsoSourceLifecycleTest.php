<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SsoSourceLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_source_edit_and_delete_revoke_access_without_reassigning_or_destroying_provenance(): void
    {
        $project = Project::factory()->create(['client_email' => 'client@example.test']);
        $owner = $this->ownerFor($project);
        $root = '/api/admin/integrations';
        $id = $this->actingAs($owner)->postJson($root.'/sources', ['name' => 'Client area', 'origin' => 'https://portal.example.test', 'ssoEnabled' => true, 'projectIds' => [$project->uuid]])->assertCreated()->json('id');
        $source = DB::table('integration_sources')->where('uuid', $id)->first();
        $key = $this->postJson($root.'/tokens', ['name' => 'SSO', 'sourceId' => $id, 'scopes' => ['clients:sso']])->assertCreated()->json();
        $headers = ['Authorization' => 'Bearer '.$key['secret']];
        $input = ['projectId' => $project->uuid, 'externalUserId' => 'client-1', 'email' => 'client@example.test', 'emailVerified' => true];
        $code = $this->postJson('/api/v1/client-sso/handoffs', $input, $headers)->assertCreated()->json('data.code');
        $this->patchJson($root.'/sources/'.$id.'/sso', ['name' => 'Renamed area', 'ssoOrigin' => 'https://portal.example.test', 'ssoEnabled' => true, 'projectIds' => [$project->uuid]])->assertOk();
        $this->assertNull(DB::table('client_sso_handoffs')->where('code_hash', hash('sha256', $code))->value('revoked_at'));
        $this->deleteJson($root.'/sources/'.$id)->assertOk();
        $this->assertDatabaseHas('integration_sources', ['id' => $source->id, 'name' => 'Renamed area', 'sso_enabled' => false]);
        $this->assertNotNull(DB::table('integration_sources')->where('id', $source->id)->value('archived_at'));
        $this->assertDatabaseHas('integration_tokens', ['uuid' => $key['id'], 'integration_source_id' => $source->id]);
        $this->assertNotNull(DB::table('integration_tokens')->where('uuid', $key['id'])->value('revoked_at'));
        $this->assertNotNull(DB::table('client_sso_handoffs')->where('code_hash', hash('sha256', $code))->value('revoked_at'));
        $this->getJson($root)->assertJsonCount(0, 'sources');
        $this->postJson('/api/v1/client-sso/handoffs', $input, $headers)->assertUnauthorized();
        $this->postJson($root.'/tokens', ['name' => 'Cannot restore', 'sourceId' => $id, 'scopes' => ['clients:sso']])->assertUnprocessable();
        $this->patchJson($root.'/sources/'.$id.'/sso', ['ssoOrigin' => 'https://portal.example.test', 'ssoEnabled' => true, 'projectIds' => []])->assertNotFound();
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_source_deletion_is_owner_only_tenant_scoped_and_requires_fresh_confirmation(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $id = $this->actingAs($owner)->postJson('/api/admin/integrations/sources', ['name' => 'Portal', 'origin' => 'https://portal.example.test'])->assertCreated()->json('id');
        $path = '/api/admin/integrations/sources/'.$id;
        $this->actingAs(User::factory()->memberOf($project->workspace)->create())->deleteJson($path)->assertForbidden();
        $this->actingAs($this->ownerFor(Project::factory()->create()))->deleteJson($path)->assertNotFound();
        $this->actingAs($owner);
        $this->travel(301)->seconds();
        $this->deleteJson($path)->assertStatus(423);
        $this->assertNull(DB::table('integration_sources')->where('uuid', $id)->value('archived_at'));
    }
}
