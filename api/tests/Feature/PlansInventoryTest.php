<?php

namespace Tests\Feature;

use App\Models\DeveloperInvitation;
use App\Models\ExternalAgent;
use App\Models\Plans\PlanVersion;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\DeveloperInvitations;
use App\Services\IntegrationTokens;
use App\Services\Plans\EntitlementResolver;
use App\Services\Plans\InvitationOccupancy;
use App\Services\Plans\LegacyInventory;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\SubscriptionAssignments;
use App\Services\Plans\WorkspaceImpact;
use App\Services\Plans\WorkspaceUsage;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlansInventoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function workspace(): array
    {
        $project = Project::factory()->create(['status' => 'active']);
        $project->clients()->delete();
        $owner = $this->ownerFor($project, ['platform_admin' => true]);

        return [$project, $owner];
    }

    private function backfill(int $workspaceId): void
    {
        $inventory = app(LegacyInventory::class);
        $inventory->bootstrap();
        do {
            $result = $inventory->process($workspaceId);
        } while ($result['state'] === 'pending');
    }

    private function businessHash(): string
    {
        $tables = ['workspaces', 'workspace_members', 'projects', 'project_clients', 'workspace_clients', 'developer_invitations', 'integration_tokens', 'client_sso_bindings', 'client_sso_handoffs', 'external_agents', 'plans', 'plan_versions', 'plan_settings', 'workspace_subscriptions', 'subscription_periods', 'plan_events', 'entitlement_client_identities', 'entitlement_client_aliases', 'entitlement_client_observations', 'entitlement_capacity_reservations', 'plan_inventory_items', 'plan_inventory_cohorts', 'owner_trial_claims'];

        return hash('sha256', json_encode(collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toArray()])->all(), JSON_THROW_ON_ERROR));
    }

    public function test_strict_dry_run_and_idempotent_compatibility_keep_legacy_data_and_no_trial(): void
    {
        [$project, $owner] = $this->workspace();
        ProjectClient::factory()->create(['project_id' => $project->id, 'email' => 'client@example.test']);
        $before = $this->businessHash();
        $this->artisan('plans:inventory --dry-run')->assertSuccessful();
        $this->assertSame($before, $this->businessHash());
        $this->artisan('plans:inventory')->assertFailed();
        $this->artisan('plans:inventory --apply')->assertSuccessful();
        $this->assertDatabaseCount('workspace_subscriptions', 1);
        $this->assertDatabaseHas('subscription_periods', ['kind' => 'compatibility', 'service_ends_at' => null, 'grace_ends_at' => null]);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $project->workspace_id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        $snapshot = app(EntitlementResolver::class)->resolve($project->workspace_id);
        $this->assertSame('available', $snapshot['state']);
        $this->assertSame('compatibility', $snapshot['phase']);
        $this->assertSame(['kind' => 'unlimited'], $snapshot['values']['developers.max']);
        $this->assertFalse($snapshot['values']['formwatch.enabled']['value']);
        $after = $this->businessHash();
        $this->artisan('plans:inventory --apply')->assertSuccessful();
        $this->assertSame($after, $this->businessHash());
    }

    public function test_counts_distinct_clients_humans_agents_and_active_project_lifecycle(): void
    {
        [$project, $owner] = $this->workspace();
        $other = Project::factory()->create(['workspace_id' => $project->workspace_id, 'status' => 'active']);
        $inactive = Project::factory()->create(['workspace_id' => $project->workspace_id, 'status' => 'inactive']);
        $other->clients()->delete();
        $inactive->clients()->delete();
        $a = ProjectClient::factory()->create(['project_id' => $project->id, 'email' => 'Shared@Example.test']);
        $b = ProjectClient::factory()->create(['project_id' => $other->id, 'email' => ' shared@example.test ']);
        ProjectClient::factory()->create(['project_id' => $inactive->id]);
        WorkspaceMember::factory()->create(['workspace_id' => $project->workspace_id, 'role' => 'admin', 'status' => 'active']);
        ExternalAgent::factory()->count(2)->create(['workspace_id' => $project->workspace_id, 'active' => true]);
        ExternalAgent::factory()->create(['workspace_id' => $project->workspace_id, 'active' => false]);
        $this->backfill($project->workspace_id);
        $usage = app(WorkspaceUsage::class)->snapshot($project->workspace_id);
        $this->assertSame(2, $usage['projects.active.max']['count']);
        $this->assertSame(2, $usage['developers.max']['count']);
        $this->assertSame(2, $usage['agents.active.max']['count']);
        $this->assertSame(1, $usage['clients.max']['count'], json_encode($usage['clients.max']));
        $this->assertSame($a->fresh()->entitlement_identity_id, $b->fresh()->entitlement_identity_id);
        $other->delete();
        $project->update(['status' => 'inactive']);
        $this->assertSame(0, app(WorkspaceUsage::class)->measure($project->workspace_id, 'clients.max')['count']);
        $other->restore();
        $this->assertSame(1, app(WorkspaceUsage::class)->measure($project->workspace_id, 'clients.max')['count']);
        $this->assertDatabaseCount('entitlement_client_identities', 2);
    }

    public function test_missing_and_changed_mapping_is_unknown_not_zero_and_never_auth_merge(): void
    {
        [$project] = $this->workspace();
        $client = ProjectClient::factory()->create(['project_id' => $project->id]);
        $this->assertNull(app(WorkspaceUsage::class)->measure($project->workspace_id, 'clients.max')['count']);
        $this->backfill($project->workspace_id);
        $id = $client->fresh()->entitlement_identity_id;
        $client->update(['email' => 'changed@example.test']);
        $measurement = app(WorkspaceUsage::class)->measure($project->workspace_id, 'clients.max');
        $this->assertSame('incomplete', $measurement['state']);
        $this->assertNull($measurement['count']);
        app(LegacyInventory::class)->process($project->workspace_id, refresh: true);
        $this->assertSame($id, $client->fresh()->entitlement_identity_id);
        $this->assertDatabaseHas('entitlement_client_observations', ['project_client_id' => $client->id, 'state' => 'needs_review']);
        $this->assertDatabaseCount('project_clients', 1);
    }

    public function test_invitation_union_accept_revoke_expiry_email_change_and_multiple_owners(): void
    {
        [$project, $owner] = $this->workspace();
        $this->backfill($project->workspace_id);
        $invite = DeveloperInvitation::factory()->create(['workspace_id' => $project->workspace_id, 'email' => 'new@example.test', 'invited_by' => $owner->id]);
        $duplicate = DeveloperInvitation::factory()->create(['workspace_id' => $project->workspace_id, 'email' => 'NEW@example.test', 'invited_by' => $owner->id]);
        app(InvitationOccupancy::class)->reconcile($project->workspace_id);
        $this->assertSame(2, app(WorkspaceUsage::class)->measure($project->workspace_id, 'developers.max')['count']);
        $url = app(DeveloperInvitations::class)->issue($invite);
        $request = Request::create('/invitation');
        $request->setLaravelSession(app('session.store'));
        $user = app(DeveloperInvitations::class)->accept($request, ['token' => explode('token=', $url)[1], 'name' => 'New member', 'password' => 'Testing-secret-123', 'password_confirmation' => 'Testing-secret-123']);
        $this->assertDatabaseHas('entitlement_capacity_reservations', ['developer_invitation_id' => $invite->id, 'state' => 'consumed']);
        $this->assertSame(2, app(WorkspaceUsage::class)->measure($project->workspace_id, 'developers.max')['count']);
        $user->update(['email' => 'different@example.test']);
        $this->assertSame(3, app(WorkspaceUsage::class)->measure($project->workspace_id, 'developers.max')['count']);
        $duplicate->update(['revoked_at' => now()]);
        app(InvitationOccupancy::class)->reconcile($project->workspace_id);
        $this->assertSame(2, app(WorkspaceUsage::class)->measure($project->workspace_id, 'developers.max')['count']);
        $expired = DeveloperInvitation::factory()->create(['workspace_id' => $project->workspace_id, 'expires_at' => now()->subSecond()]);
        app(InvitationOccupancy::class)->reconcile($project->workspace_id);
        $this->assertDatabaseHas('entitlement_capacity_reservations', ['developer_invitation_id' => $expired->id, 'state' => 'released']);
        WorkspaceMember::where('workspace_id', $project->workspace_id)->where('user_id', $user->id)->update(['role' => 'owner']);
        $measurement = app(WorkspaceUsage::class)->measure($project->workspace_id, 'developers.max');
        $this->assertSame('incomplete', $measurement['state']);
        $this->assertContains('ownership_review_required', $measurement['reason_codes']);
    }

    public function test_preview_published_target_read_only_enforce_disabled_and_security_boundary(): void
    {
        [$project, $owner] = $this->workspace();
        Project::factory()->count(2)->create(['workspace_id' => $project->workspace_id, 'status' => 'active']);
        $this->seed(PlansFoundationSeeder::class);
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $owner->id, 'revision' => 1]);
        $version = PlanVersion::whereHas('plan', fn ($q) => $q->where('code', 'basic'))->firstOrFail();
        $version = app(PlanCatalog::class)->publish($owner, $version->id, 1, 'publish-basic');
        $this->backfill($project->workspace_id);
        DB::table('plan_settings')->where('id', 1)->update(['rollout_mode' => 'enforce']);
        DB::table('workspace_subscriptions')->where('workspace_id', $project->workspace_id)->update(['enforcement_mode' => 'enforce']);
        $hash = $this->businessHash();
        $preview = app(WorkspaceImpact::class)->preview($project->workspace_id, $version->uuid);
        $this->assertSame($hash, $this->businessHash());
        $decision = collect($preview['decisions'])->firstWhere('key', 'projects.active.max');
        $this->assertSame('would_deny', $decision['state']);
        $this->assertSame(2, $decision['excess']);
        $this->assertFalse($preview['policy']['executionGuardsEnabled']);
        $this->assertSame('observe', $preview['policy']['effectiveMode']);
        $this->assertTrue($preview['dataPreserved']);
        $this->actingAs($owner)->getJson('/api/platform/workspace-inventory/'.$project->workspace->uuid.'?versionId='.$version->uuid)->assertOk()->assertJsonPath('data.previewOnly', true);
        $other = User::factory()->owner()->create(['platform_admin' => true]);
        $this->actingAs($other)->getJson('/api/platform/workspace-inventory')->assertForbidden();
        $this->getJson('/api/platform/plans')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/platform/workspace-inventory?limit=1000')->assertUnprocessable();
        $this->travel(301)->seconds();
        $this->getJson('/api/platform/workspace-inventory')->assertStatus(423);
    }

    public function test_chunk_checkpoint_resume_cutoff_and_pinned_subscription_preservation(): void
    {
        [$project] = $this->workspace();
        ProjectClient::factory()->count(205)->sequence(fn ($sequence) => ['email' => 'chunk-'.$sequence->index.'@example.test'])->create(['project_id' => $project->id]);
        $inventory = app(LegacyInventory::class);
        $cohort = $inventory->bootstrap();
        $first = $inventory->process($project->workspace_id);
        $this->assertSame('pending', $first['state']);
        $this->assertDatabaseCount('entitlement_client_observations', 200);
        $subscription = DB::table('workspace_subscriptions')->first();
        $second = $inventory->process($project->workspace_id);
        $this->assertSame('processed', $second['state']);
        $this->assertDatabaseCount('entitlement_client_observations', 205);
        $this->assertEquals($subscription, DB::table('workspace_subscriptions')->first());
        $this->assertSame(205, app(WorkspaceUsage::class)->measure($project->workspace_id, 'clients.max')['count']);
        $new = Workspace::factory()->create();
        $this->assertGreaterThan($cohort->workspace_cutoff_id, $new->id);
        $this->artisan('plans:inventory --apply')->assertSuccessful();
        $this->assertDatabaseMissing('workspace_subscriptions', ['workspace_id' => $new->id]);
        $this->artisan('plans:inventory --apply --extend-cohort')->assertSuccessful();
        $this->assertDatabaseHas('workspace_subscriptions', ['workspace_id' => $new->id]);
        $audit = json_encode(DB::table('plan_events')->pluck('diff_json')->all());
        $this->assertStringNotContainsString('@', $audit);
        $this->assertStringNotContainsString('token_hash', $audit);
    }

    public function test_source_bound_proofs_separate_shared_mailbox_and_alias_collisions_need_review(): void
    {
        [$project] = $this->workspace();
        $second = Project::factory()->create(['workspace_id' => $project->workspace_id]);
        $second->clients()->delete();
        $clients = [ProjectClient::factory()->create(['project_id' => $project->id, 'email' => 'shared@example.test']), ProjectClient::factory()->create(['project_id' => $second->id, 'email' => 'shared@example.test'])];
        foreach ($clients as $index => $client) {
            $source = DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'name' => 'Source '.$index, 'created_at' => now(), 'updated_at' => now()]);
            $binding = DB::table('client_sso_bindings')->insertGetId(['workspace_id' => $project->workspace_id, 'integration_source_id' => $source, 'external_hash' => hash('sha256', 'subject-'.$index), 'email_hash' => hash('sha256', 'shared@example.test'), 'created_at' => now()]);
            $token = app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'SSO', 'sourceId' => $source, 'scopes' => ['clients:sso']]);
            DB::table('client_sso_handoffs')->insert(['code_hash' => hash('sha256', 'proof-'.$index), 'workspace_id' => $project->workspace_id, 'integration_source_id' => $source, 'integration_token_id' => DB::table('integration_tokens')->where('uuid', $token['id'])->value('id'), 'client_sso_binding_id' => $binding, 'project_id' => $client->project_id, 'project_client_id' => $client->id, 'expires_at' => now()->subMinute(), 'consumed_at' => now()->subMinutes(2), 'created_at' => now()->subMinutes(3)]);
        }
        $this->backfill($project->workspace_id);
        $this->assertNotSame($clients[0]->fresh()->entitlement_identity_id, $clients[1]->fresh()->entitlement_identity_id);
        $measurement = app(WorkspaceUsage::class)->measure($project->workspace_id, 'clients.max');
        $this->assertSame(2, $measurement['count']);
        $this->assertSame('incomplete', $measurement['state']);
        $this->assertContains('client_accounting_review_required', $measurement['reason_codes']);
        $this->assertDatabaseHas('entitlement_client_aliases', ['alias_kind' => 'email', 'state' => 'ambiguous', 'identity_id' => null]);
        $this->assertDatabaseCount('client_sso_bindings', 2);
        $this->assertDatabaseCount('client_sso_handoffs', 2);
        $ids = $clients[0]->fresh()->entitlement_identity_id.':'.$clients[1]->fresh()->entitlement_identity_id;
        app(LegacyInventory::class)->process($project->workspace_id, refresh: true);
        $this->assertSame($ids, $clients[0]->fresh()->entitlement_identity_id.':'.$clients[1]->fresh()->entitlement_identity_id);
    }

    public function test_shared_agent_key_does_not_collapse_agent_identity_and_missing_mapping_never_allows_target(): void
    {
        [$project, $owner] = $this->workspace();
        $agents = ExternalAgent::factory()->count(2)->create(['workspace_id' => $project->workspace_id]);
        $key = app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'Shared', 'shared' => true, 'scopes' => ['agent:context']]);
        foreach ($agents as $agent) {
            DB::table('agent_credential_links')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'external_agent_id' => $agent->id, 'integration_token_id' => DB::table('integration_tokens')->where('uuid', $key['id'])->value('id'), 'created_at' => now()]);
        }
        $this->assertSame(2, app(WorkspaceUsage::class)->measure($project->workspace_id, 'agents.active.max')['count']);
        $agents[0]->delete();
        $this->assertSame(1, app(WorkspaceUsage::class)->measure($project->workspace_id, 'agents.active.max')['count']);
        $this->assertDatabaseCount('agent_credential_links', 2);
    }

    public function test_compatibility_is_not_editable_through_catalog_and_failed_audit_rolls_back_chunk(): void
    {
        [$project, $owner] = $this->workspace();
        $inventory = app(LegacyInventory::class);
        $inventory->bootstrap();
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $owner->id, 'revision' => 1]);
        $legacy = PlanVersion::findOrFail(DB::table('plan_settings')->value('legacy_version_id'));
        $this->actingAs($owner)->postJson('/api/platform/plans/'.$legacy->plan->uuid.'/draft', [], ['If-Match' => '"revision:1"', 'Idempotency-Key' => 'forbidden-compatibility-draft'])->assertUnprocessable();
        $before = $this->businessHash();
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'plan_events') && in_array('inventory.workspace_accounted', $query->bindings, true)) {
                throw new \RuntimeException('Synthetic required audit failure');
            }
        });
        try {
            $inventory->process($project->workspace_id);
            $this->fail('Required audit failure must roll back.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic required audit failure', $e->getMessage());
        }
        $this->assertSame($before, $this->businessHash());
    }

    public function test_manual_pinned_subscription_and_grants_survive_inventory_while_other_workspace_gets_compatibility(): void
    {
        [$project, $owner] = $this->workspace();
        $this->seed(PlansFoundationSeeder::class);
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $owner->id, 'revision' => 1]);
        $version = PlanVersion::whereHas('plan', fn ($q) => $q->where('code', 'basic'))->firstOrFail();
        $version = app(PlanCatalog::class)->publish($owner, $version->id, 1, 'pinned-basic');
        app(SubscriptionAssignments::class)->pin($owner, $project->workspace_id, $version->id, 0, 'manual-existing-pin');
        $before = [DB::table('workspace_subscriptions')->first(), DB::table('subscription_periods')->first()];
        $legacy = Workspace::factory()->create();
        $this->artisan('plans:inventory --apply')->assertSuccessful();
        $this->assertEquals($before, [DB::table('workspace_subscriptions')->where('workspace_id', $project->workspace_id)->first(), DB::table('subscription_periods')->where('workspace_id', $project->workspace_id)->first()]);
        $this->assertDatabaseHas('subscription_periods', ['workspace_id' => $legacy->id, 'kind' => 'compatibility']);
        $this->assertDatabaseHas('plan_inventory_items', ['workspace_id' => $legacy->id, 'state' => 'needs_review']);
    }

    public function test_invalid_project_scoped_invitation_is_excluded_by_existing_manager_rules(): void
    {
        [$project, $owner] = $this->workspace();
        $this->backfill($project->workspace_id);
        $invitation = DeveloperInvitation::factory()->create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'email' => 'valid@example.test', 'project_scoped' => true, 'project_ids' => [$project->id]]);
        app(InvitationOccupancy::class)->reconcile($project->workspace_id);
        $this->assertSame(2, app(WorkspaceUsage::class)->measure($project->workspace_id, 'developers.max')['count']);
        $project->delete();
        $this->assertSame(1, app(WorkspaceUsage::class)->measure($project->workspace_id, 'developers.max')['count']);
        app(InvitationOccupancy::class)->reconcile($project->workspace_id);
        $this->assertDatabaseHas('entitlement_capacity_reservations', ['developer_invitation_id' => $invitation->id, 'state' => 'released']);
        $project->restore();
        $this->assertSame(2, app(WorkspaceUsage::class)->measure($project->workspace_id, 'developers.max')['count']);
    }

    public function test_shadow_reservation_failure_does_not_block_existing_invitation_issuance(): void
    {
        [$project, $owner] = $this->workspace();
        $this->backfill($project->workspace_id);
        $i = DeveloperInvitation::factory()->create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'email' => 'invite@example.test']);
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'entitlement_capacity_reservations')) {
                throw new \RuntimeException('Synthetic shadow persistence failure');
            }
        });
        $link = app(DeveloperInvitations::class)->issue($i);
        $this->assertStringContainsString('/invitation#token=', $link);
        $this->assertSame(hash('sha256', explode('token=', $link)[1]), $i->fresh()->token_hash);
        $this->assertDatabaseCount('entitlement_capacity_reservations', 0);
        $usage = app(WorkspaceUsage::class)->measure($project->workspace_id, 'developers.max');
        $this->assertSame(2, $usage['count']);
        $this->assertTrue($usage['reservation_refresh_required']);
    }

    public function test_conflicting_contact_hint_and_email_alias_keep_new_link_isolated(): void
    {
        [$project] = $this->workspace();
        $clients = [];
        foreach ([1, 2] as $index) {
            $email = 'contact-'.$index.'@example.test';
            $contact = DB::table('workspace_clients')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'name' => 'Accounting hint', 'email' => $email, 'email_hash' => hash('sha256', $email), 'created_at' => now(), 'updated_at' => now()]);
            $clients[] = ProjectClient::factory()->create(['project_id' => $project->id, 'email' => $email, 'workspace_client_id' => $contact]);
        }
        $this->backfill($project->workspace_id);
        $third = ProjectClient::factory()->create(['project_id' => $project->id, 'email' => $clients[1]->email, 'workspace_client_id' => $clients[0]->workspace_client_id]);
        app(LegacyInventory::class)->process($project->workspace_id, refresh: true);
        $ids = [$clients[0]->fresh()->entitlement_identity_id, $clients[1]->fresh()->entitlement_identity_id, $third->fresh()->entitlement_identity_id];
        $this->assertCount(3, array_unique($ids));
        $usage = app(WorkspaceUsage::class)->measure($project->workspace_id, 'clients.max');
        $this->assertSame(3, $usage['count']);
        $this->assertSame('incomplete', $usage['state']);
        $this->assertDatabaseHas('entitlement_client_observations', ['project_client_id' => $third->id, 'state' => 'needs_review']);
    }
}
