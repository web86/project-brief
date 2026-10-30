<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GlobalWorkspaceBrandingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_personal_bootstrap_has_only_the_unambiguous_active_workspace_brand_and_keeps_personal_preferences_global(): void
    {
        $a = Workspace::factory()->create(['branding' => ['visual' => ['companyName' => 'Agency A', 'primaryColor' => '#17695d'], 'business' => ['account' => 'PRIVATE BANK'], 'contact' => ['email' => 'private@example.test']]]);
        $b = Workspace::factory()->create(['branding' => ['visual' => ['companyName' => 'Agency B', 'primaryColor' => '#7354a3']]]);
        $user = User::factory()->owner($a)->owner($b)->create();
        $this->actingAs($user);
        $root = '/api/admin/notifications';
        $this->getJson($root)->assertOk()->assertJsonPath('workspace', null);
        foreach ([$a, $b, $a] as $workspace) {
            $this->postJson('/api/admin/workspaces/'.$workspace->uuid.'/select')->assertOk();
            $this->getJson($root)->assertOk()->assertJsonPath('workspace.id', $workspace->uuid)->assertJsonPath('workspace.branding.companyName', $workspace->branding['visual']['companyName'])->assertDontSee('PRIVATE BANK')->assertDontSee('private@example.test');
        }
        $this->patchJson($root, ['preferences' => ['email' => false, 'push' => true, 'telegram' => false]])->assertOk();
        $this->postJson('/api/admin/workspaces/'.$b->uuid.'/select')->assertOk();
        $this->getJson($root)->assertJsonPath('preferences.email', false)->assertJsonPath('workspace.branding.companyName', 'Agency B');
    }

    public function test_stale_foreign_or_missing_membership_does_not_leak_a_brand_or_block_personal_routes(): void
    {
        $workspace = Workspace::factory()->create(['branding' => ['visual' => ['companyName' => 'Only workspace']]]);
        $user = User::factory()->memberOf($workspace)->create();
        $foreign = Workspace::factory()->create(['branding' => ['visual' => ['companyName' => 'FOREIGN BRAND']]]);
        $this->actingAs($user)->getJson('/api/admin/notifications')->assertOk()->assertJsonPath('workspace.branding.companyName', 'Only workspace');
        foreach ([999999, $foreign->id, (string) $workspace->id] as $selection) {
            $this->withSession(['workspace_id' => $selection])->getJson('/api/admin/notifications')->assertOk()->assertJsonPath('workspace', null)->assertDontSee('FOREIGN BRAND');
            $this->getJson('/api/admin/account')->assertOk();
        }
        $user->workspaceMembers()->delete();
        $this->getJson('/api/admin/notifications')->assertOk()->assertJsonPath('workspace', null);
    }
}
