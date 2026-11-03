<?php

namespace Tests\Feature;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuthOnboarding;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\PlatformPolicyCatalog;
use App\Services\RegistrationReadiness;
use App\Services\TrialEligibility;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TrialActivationFixture;
use Tests\TestCase;

class TrialEligibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function publishTargets(): void
    {
        $primary = User::factory()->create(['platform_admin' => true]);
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $primary->id, 'revision' => 1]);
        $this->seed(PlansFoundationSeeder::class);
        foreach (['basic', 'studio'] as $code) {
            $v = PlanVersion::whereHas('plan', fn ($q) => $q->where('code', $code))->firstOrFail();
            app(PlanCatalog::class)->publish($primary, $v->id, 1, 'fixture-publish-'.$code);
        }
        $policy = app(PlatformPolicyCatalog::class)->publish($primary, PlatformPolicyVersion::firstOrFail()->id, 1, 'fixture-policy');
        DB::table('plan_settings')->where('id', 1)->update(['current_policy_version_id' => $policy->id]);
    }

    private function owner(string $email = 'trial-owner@example.test', ?CarbonImmutable $at = null): User
    {
        config(['onboarding.subject_retention_days' => 365]);
        $user = User::factory()->unverified()->create(['email' => $email]);
        app(AuthOnboarding::class)->pending($user);
        $user->forceFill(['email_verified_at' => $at ?? now('UTC')])->save();
        if ($at) {
            DB::table('account_onboardings')->where('user_id', $user->id)->update(['verified_at' => $at->format('Y-m-d H:i:s.u')]);
        }
        app(AuthOnboarding::class)->prepare($user);

        return $user;
    }

    public function test_production_preparation_only_and_isolated_activation_pins_exact_utc_seven_days_without_grace(): void
    {
        $this->publishTargets();
        $at = CarbonImmutable::parse('2026-11-03T12:34:56.123456+03:00')->utc();
        $owner = $this->owner(at: $at);
        $this->assertFalse(app(RegistrationReadiness::class)->activationEnabled());
        $this->assertDatabaseCount('subscription_periods', 0);
        $this->assertDatabaseHas('owner_trial_claims', ['starts_at' => null, 'ends_at' => null]);
        $this->travelTo($at->addDays(2));
        $period = TrialActivationFixture::activate($owner);
        $this->assertTrue(CarbonImmutable::parse($period->starts_at, 'UTC')->equalTo($at));
        $this->assertTrue(CarbonImmutable::parse($period->service_ends_at, 'UTC')->equalTo($at->addSeconds(604800)));
        $this->assertNull($period->grace_ends_at);
        $this->assertNotNull($period->policy_version_id);
        $this->assertNotNull($period->fallback_version_id);
        $this->travel(1)->days();
        $this->assertSame($period->id, TrialActivationFixture::activate($owner)->id);
        $owner->forceFill(['email' => 'new-trial-address@example.test'])->save();
        DB::transaction(fn () => app(TrialEligibility::class)->rememberVerifiedSubjects($owner));
        $this->assertSame($period->service_ends_at, TrialActivationFixture::activate($owner)->service_ends_at);
        $this->assertDatabaseCount('subscription_periods', 1);
        $this->assertDatabaseCount('owner_trial_claims', 1);
    }

    public function test_second_workspace_cannot_receive_the_owners_previous_claim(): void
    {
        $this->publishTargets();
        $owner = $this->owner();
        TrialActivationFixture::activate($owner);
        $second = Workspace::factory()->create();
        $second->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        try {
            TrialActivationFixture::activate($owner, $second->id);
            $this->fail('Second trial created');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('subscription_periods', 1);
        $this->assertDatabaseCount('owner_trial_claims', 1);
    }

    public function test_durable_verified_email_alias_denies_delete_and_reregister_without_merging_accounts(): void
    {
        $original = $this->owner();
        $claim = DB::table('owner_trial_claims')->first();
        $original->workspaceMembers()->delete();
        $original->delete();
        $this->assertDatabaseHas('owner_trial_claims', ['id' => $claim->id, 'owner_user_id' => null]);
        $again = $this->owner();
        $newClaim = DB::table('owner_trial_claims')->where('owner_user_id', $again->id)->first();
        $this->assertSame('denied', $newClaim->state);
        $this->assertSame('verified_subject_previously_claimed', $newClaim->reason_code);
        $this->assertDatabaseCount('owner_trial_claim_subjects', 1);
        $this->assertDatabaseCount('subscription_periods', 0);
    }

    public function test_email_and_provider_aliases_accumulate_without_resetting_claim_or_timestamps(): void
    {
        $owner = $this->owner();
        $claim = DB::table('owner_trial_claims')->where('owner_user_id', $owner->id)->first();
        DB::table('user_external_identities')->insert(['user_id' => $owner->id, 'provider_key' => 'github', 'issuer_key' => 'https://github.com', 'subject_hash' => app(TrialEligibility::class)->hash('https://github.com', '42'), 'hash_key_version' => 1, 'verified_at' => now()]);
        $owner->forceFill(['email' => 'another@example.test'])->save();
        DB::transaction(fn () => app(TrialEligibility::class)->rememberVerifiedSubjects($owner));
        DB::transaction(fn () => app(TrialEligibility::class)->rememberVerifiedSubjects($owner));
        $this->assertDatabaseCount('owner_trial_claim_subjects', 3);
        config(['onboarding.subject_retention_days' => -1]);
        $owner->forceFill(['email' => 'invalid-retention@example.test'])->save();
        DB::transaction(fn () => app(TrialEligibility::class)->rememberVerifiedSubjects($owner));
        $this->assertDatabaseCount('owner_trial_claim_subjects', 3);
        $current = DB::table('owner_trial_claims')->where('id', $claim->id)->first();
        $this->assertSame($claim->verified_at, $current->verified_at);
        $this->assertNull($current->starts_at);
        $this->assertNull($current->ends_at);
    }

    public function test_legacy_owner_and_imported_workspace_never_become_eligible_through_provisioning(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->owner($workspace)->create();
        try {
            app(AuthOnboarding::class)->prepare($owner);
            $this->fail('Legacy owner provisioned');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('owner_trial_claims', 0);
    }

    public function test_unpublished_targets_and_unconfigured_retention_fail_closed_without_consuming_a_trial(): void
    {
        $owner = $this->owner();
        $claim = DB::table('owner_trial_claims')->where('owner_user_id', $owner->id)->first();
        try {
            TrialActivationFixture::activate($owner);
            $this->fail('Unpublished policy accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('subscription_periods', 0);
        $this->assertSame($claim->verified_at, DB::table('owner_trial_claims')->where('id', $claim->id)->value('verified_at'));
        config(['onboarding.subject_retention_days' => null]);
        $other = User::factory()->create();
        app(AuthOnboarding::class)->pending($other);
        app(AuthOnboarding::class)->prepare($other);
        $this->assertDatabaseHas('owner_trial_claims', ['owner_user_id' => $other->id, 'state' => 'pending', 'reason_code' => 'claim_retention_unconfigured', 'verified_at' => null]);
        $this->assertDatabaseCount('owner_trial_claim_subjects', 1);
    }
}
