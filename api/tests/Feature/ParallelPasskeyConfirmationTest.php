<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WebAuthnDevice;
use Tests\TestCase;

class ParallelPasskeyConfirmationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fortify.passkeys.allowed_origins' => ['http://localhost'], 'fortify.passkeys.relying_party_id' => 'localhost', 'passkeys.allowed_origins' => ['http://localhost'], 'passkeys.relying_party_id' => 'localhost']);
    }

    private function device(): array
    {
        $owner = User::factory()->owner()->create(['platform_admin' => true]);
        $owner->passkeys()->delete();
        $this->actingAs($owner);
        $device = new WebAuthnDevice;
        $registration = $this->getJson('/api/security/passkey/register/options')->assertOk()->json();
        $this->postJson('/api/security/passkey/register', ['attemptId' => $registration['attemptId'] ?? null, 'name' => 'Two tabs device', 'credential' => $device->registration($registration['options'])])->assertOk();

        return [$owner, $device];
    }

    public function test_preparing_a_second_tab_does_not_delete_the_first_tabs_pending_challenge(): void
    {
        $this->device();
        $this->getJson('/api/security/passkey/confirm/options')->assertOk();
        $first = DB::table('account_security_challenges')->where('purpose', 'passkey_confirm')->first();
        $this->getJson('/api/security/passkey/confirm/options')->assertOk();
        $this->assertDatabaseHas('account_security_challenges', ['id' => $first->id, 'purpose' => 'passkey_confirm']);
        $this->assertSame(2, DB::table('account_security_challenges')->where('purpose', 'passkey_confirm')->count());
    }

    public function test_independent_attempts_can_finish_in_either_order_survive_session_rotation_and_are_single_use(): void
    {
        [$owner, $device] = $this->device();
        $first = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json();
        $second = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json();
        $this->assertNotSame($first['attemptId'], $second['attemptId']);
        foreach ([$second, $first] as $attempt) {
            $body = ['attemptId' => $attempt['attemptId'], 'credential' => $device->assertion($owner, $attempt['options'])];
            $this->postJson('/api/security/passkey/confirm', $body)->assertOk()->assertJsonPath('fresh', true);
            $this->postJson('/api/security/passkey/confirm', $body)->assertUnprocessable()->assertJsonPath('code', 'PK_CHALLENGE_INVALID');
        }
    }

    public function test_attempt_id_cannot_be_swapped_and_a_different_session_cannot_consume_it(): void
    {
        [$owner, $device] = $this->device();
        $first = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json();
        $second = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json();
        $this->postJson('/api/security/passkey/confirm', ['attemptId' => $first['attemptId'], 'credential' => $device->assertion($owner, $second['options'])])->assertUnprocessable()->assertJsonPath('code', 'PK_CHALLENGE_MISMATCH');
        $this->assertDatabaseHas('account_security_challenges', ['id' => $second['attemptId']]);
        $this->app['session.store']->regenerate(true);
        $this->withSession([]);
        $this->postJson('/api/security/passkey/confirm', ['attemptId' => $second['attemptId'], 'credential' => $device->assertion($owner, $second['options'])])->assertUnprocessable()->assertJsonPath('code', 'PK_CHALLENGE_SESSION');
        $this->assertDatabaseHas('account_security_challenges', ['id' => $second['attemptId']]);
    }

    public function test_challenge_context_cannot_be_used_after_switching_workspace(): void
    {
        [$owner, $device] = $this->device();
        $first = $owner->workspaceMembers()->firstOrFail()->workspace;
        $second = Workspace::factory()->create();
        $second->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        $this->postJson('/api/admin/workspaces/'.$first->uuid.'/select')->assertOk();
        $attempt = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json();
        $this->postJson('/api/admin/workspaces/'.$second->uuid.'/select')->assertOk();
        $this->postJson('/api/security/passkey/confirm', ['attemptId' => $attempt['attemptId'], 'credential' => $device->assertion($owner, $attempt['options'])])->assertUnprocessable()->assertJsonPath('code', 'PK_SESSION_CHANGED');
        $this->assertDatabaseHas('account_security_challenges', ['id' => $attempt['attemptId']]);
    }

    public function test_wrong_attempt_purpose_session_user_and_expiry_do_not_grant_assurance(): void
    {
        [$owner, $device] = $this->device();
        $attempt = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json();
        $body = ['attemptId' => $attempt['attemptId'], 'credential' => $device->assertion($owner, $attempt['options'])];
        $this->postJson('/api/security/passkey/login', $body)->assertUnprocessable()->assertJsonPath('code', 'PK_CHALLENGE_INVALID');
        $this->assertDatabaseHas('account_security_challenges', ['id' => $attempt['attemptId']]);
        $this->actingAs(User::factory()->owner()->create())->postJson('/api/security/passkey/confirm', $body)->assertUnprocessable();
        $this->actingAs($owner->fresh());
        $expired = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json();
        $this->travel(121)->seconds();
        $this->postJson('/api/security/passkey/confirm', ['attemptId' => $expired['attemptId'], 'credential' => $device->assertion($owner, $expired['options'])])->assertUnprocessable()->assertJsonPath('code', 'PK_CHALLENGE_EXPIRED');
        $retry = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json();
        $this->postJson('/api/security/passkey/confirm', ['attemptId' => $retry['attemptId'], 'credential' => $device->assertion($owner, $retry['options'])])->assertOk();
    }
}
