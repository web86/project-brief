<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OnboardingMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_additive_migration_repeats_and_repairs_an_index_without_reclassifying_existing_accounts(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project, ['email_verified_at' => null]);
        $before = DB::table('users')->where('id', $owner->id)->first();
        $migration = require database_path('migrations/2026_11_03_081230_add_account_onboarding.php');
        $migration->up();
        Schema::table('social_auth_attempts', fn ($table) => $table->dropIndex('social_auth_attempts_state_expires_at_index'));
        $migration->up();
        $after = DB::table('users')->where('id', $owner->id)->first();
        $this->assertSame($before->email, $after->email);
        $this->assertSame($before->password, $after->password);
        $this->assertSame($before->auth_session_version, $after->auth_session_version);
        $this->assertNull($after->email_verified_at);
        $this->assertTrue((bool) $after->password_login_enabled);
        $this->assertTrue(Schema::hasIndex('social_auth_attempts', 'social_auth_attempts_state_expires_at_index'));
        $this->assertDatabaseCount('account_onboardings', 0);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        $this->actingAs($owner)->getJson('/api/admin/me')->assertOk();
    }
}
