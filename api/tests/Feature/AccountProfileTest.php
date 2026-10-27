<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Models\PushSubscription;
use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'test@example.test']);
        $this->user = User::factory()->owner()->create(['password' => 'Current-password']);
        $this->actingAs($this->user);
    }

    private function emailToken(string $email = 'new@example.test'): string
    {
        $this->postJson('/api/admin/account/email', ['email' => $email, 'current_password' => 'Current-password'])->assertOk();
        parse_str(parse_url(Mail::sent(AccountActionMail::class)->last()->actionUrl, PHP_URL_FRAGMENT), $fragment);

        return $fragment['email'];
    }

    public function test_profile_is_global_and_rejects_id_role_and_direct_email_changes(): void
    {
        $foreign = User::factory()->owner()->create();
        $this->withSession(['workspace_id' => 99999])->getJson('/api/admin/account')->assertOk()->assertJsonPath('user.email', $this->user->email);
        $this->patchJson('/api/admin/account', ['name' => 'New name', 'preferredLocale' => 'en'])->assertOk()->assertJsonPath('user.name', 'New name');
        $this->patchJson('/api/admin/account', ['name' => 'Hacker', 'preferredLocale' => 'en', 'id' => $foreign->id, 'role' => 'owner', 'email' => 'changed@example.test'])->assertUnprocessable();
        $this->assertNotSame('Hacker', $foreign->fresh()->name);
        $this->assertDatabaseCount('workspace_members', 2);
        $this->assertArrayNotHasKey('auth_session_version', $this->user->fresh()->toArray());
    }

    public function test_email_confirmation_is_single_use_and_preserves_identity_and_notification_channels(): void
    {
        $old = $this->user->email;
        $id = $this->user->id;
        $preferenceId = $this->getJson('/api/admin/notifications')->assertOk()->assertJsonMissingPath('user.id')->json('user.notificationPreferenceId');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preferenceId);
        $members = $this->user->workspaceMembers()->pluck('id')->all();
        PushSubscription::factory()->create(['user_id' => $id]);
        TelegramConnection::factory()->create(['user_id' => $id]);
        $this->user->forceFill(['notification_preferences' => ['email' => false, 'push' => true, 'telegram' => true]])->save();
        Password::broker('users')->createToken($this->user);
        $token = $this->emailToken();
        $this->assertSame($old, $this->user->fresh()->email);
        $this->assertSame(hash('sha256', $token), $this->user->fresh()->email_change_token_hash);
        $this->postJson('/api/admin/account/email/confirm', ['token' => $token])->assertOk()->assertJsonPath('user.email', 'new@example.test')->assertJsonPath('user.notificationPreferenceId', $preferenceId)->assertJsonPath('pendingEmail', null);
        $this->postJson('/api/admin/account/email/confirm', ['token' => $token])->assertGone();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame($members, $this->user->workspaceMembers()->pluck('id')->all());
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertDatabaseCount('telegram_connections', 1);
        $this->assertFalse($this->user->fresh()->notificationPreferences()['email']);
        Mail::assertSent(AccountActionMail::class, fn ($mail) => $mail->hasTo($old));
        $this->postJson('/api/admin/logout');
        $this->postJson('/api/admin/login', ['email' => $old, 'password' => 'Current-password'])->assertUnprocessable();
        $this->postJson('/api/admin/login', ['email' => 'new@example.test', 'password' => 'Current-password'])->assertOk();
    }

    public function test_email_requires_current_password_unique_address_and_matching_authenticated_account(): void
    {
        $this->postJson('/api/admin/account/email', ['email' => 'new@example.test', 'current_password' => 'wrong'])->assertUnprocessable();
        $other = User::factory()->owner()->create(['email' => 'taken@example.test']);
        $this->postJson('/api/admin/account/email', ['email' => 'TAKEN@example.test', 'current_password' => 'Current-password'])->assertUnprocessable();
        $token = $this->emailToken();
        $this->actingAs($other)->postJson('/api/admin/account/email/confirm', ['token' => $token])->assertGone();
        $this->assertNotNull($this->user->fresh()->pending_email);
    }

    public function test_expired_cancelled_and_replaced_email_links_fail(): void
    {
        $token = $this->emailToken();
        $this->user->fresh()->forceFill(['email_change_expires_at' => now()->subSecond()])->save();
        $this->postJson('/api/admin/account/email/confirm', ['token' => $token])->assertGone();
        $next = $this->emailToken('other@example.test');
        $this->postJson('/api/admin/account/email/confirm', ['token' => $token])->assertGone();
        $this->deleteJson('/api/admin/account/email')->assertOk();
        $this->postJson('/api/admin/account/email/confirm', ['token' => $next])->assertGone();
    }

    public function test_password_checks_current_confirmation_length_and_reuse_then_revokes_other_sessions(): void
    {
        foreach ([['current_password' => 'wrong', 'password' => 'Long-new-password', 'password_confirmation' => 'Long-new-password'], ['current_password' => 'Current-password', 'password' => 'short', 'password_confirmation' => 'short'], ['current_password' => 'Current-password', 'password' => 'Current-password', 'password_confirmation' => 'Current-password'], ['current_password' => 'Current-password', 'password' => 'Long-new-password', 'password_confirmation' => 'different']] as $body) {
            $this->postJson('/api/admin/account/password', $body)->assertUnprocessable();
        }
        $long = str_repeat('a', 80).'secret-ending';
        $this->postJson('/api/admin/account/password', ['current_password' => 'Current-password', 'password' => $long, 'password_confirmation' => $long])->assertOk();
        $this->assertTrue(Hash::check($long, $this->user->fresh()->password));
        $this->assertFalse(Hash::check(str_repeat('a', 80).'other-ending', $this->user->fresh()->password));
        $this->assertSame(1, $this->user->fresh()->auth_session_version);
        $this->getJson('/api/admin/account')->assertOk();
        $this->withSession(['auth_session_version' => 0])->getJson('/api/admin/account')->assertUnauthorized();
    }

    public function test_password_broker_responses_are_uniform_and_reset_is_single_use_and_revokes_sessions(): void
    {
        $this->postJson('/api/admin/logout');
        $this->user->forceFill(['pending_email' => 'unconfirmed@example.test', 'email_change_token_hash' => hash('sha256', 'pending'), 'email_change_expires_at' => now()->addHour()])->save();
        $known = $this->postJson('/api/admin/password/forgot', ['email' => $this->user->email])->assertOk()->json();
        $unknown = $this->postJson('/api/admin/password/forgot', ['email' => 'absent@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        parse_str(parse_url(Mail::sent(AccountActionMail::class)->last()->actionUrl, PHP_URL_FRAGMENT), $fragment);
        $body = ['email' => $this->user->email, 'token' => $fragment['token'], 'recovery_code' => $this->user->fresh()->recoveryCodes()[0], 'password' => 'Recovered-password', 'password_confirmation' => 'Recovered-password'];
        $this->postJson('/api/admin/password/reset', $body)->assertOk();
        $this->postJson('/api/admin/password/reset', $body)->assertUnprocessable();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertNull($this->user->fresh()->pending_email);
        $this->assertSame(1, $this->user->fresh()->auth_session_version);
        $this->actingAs($this->user->fresh())->withSession(['auth_session_version' => 0])->getJson('/api/admin/projects')->assertUnauthorized();
    }

    public function test_password_broker_expiration_and_throttling_are_enforced(): void
    {
        $token = Password::broker('users')->createToken($this->user);
        DB::table('password_reset_tokens')->update(['created_at' => now()->subMinutes(61)]);
        $this->postJson('/api/admin/password/reset', ['email' => $this->user->email, 'token' => $token, 'password' => 'Recovered-password', 'password_confirmation' => 'Recovered-password'])->assertUnprocessable();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/admin/password/forgot', ['email' => $this->user->email])->assertOk();
        }
        $this->postJson('/api/admin/password/forgot', ['email' => $this->user->email])->assertTooManyRequests();
    }

    public function test_avatar_is_opt_in_normalized_private_and_old_files_are_deleted(): void
    {
        Storage::fake('local');
        $this->getJson('/api/admin/account')->assertOk()->assertJsonPath('user.avatarUrl', null)->assertJsonPath('user.avatarSource', 'initials');
        $this->postJson('/api/admin/account/avatar', ['source' => 'gravatar'])->assertOk()->assertJsonPath('user.avatarUrl', 'https://gravatar.com/avatar/'.hash('sha256', mb_strtolower(trim($this->user->email))).'?s=160&d=404');
        $result = $this->postJson('/api/admin/account/avatar', ['source' => 'upload', 'avatar' => UploadedFile::fake()->image('portrait.jpg', 400, 600)])->assertOk();
        $path = $this->user->fresh()->avatar_path;
        Storage::disk('local')->assertExists($path);
        $this->assertSame([256, 256], array_slice(getimagesize(Storage::disk('local')->path($path)), 0, 2));
        $this->get($result->json('user.avatarUrl'))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $other = User::factory()->owner()->create();
        $this->actingAs($other)->get($result->json('user.avatarUrl'))->assertNotFound();
        $this->actingAs($this->user)->postJson('/api/admin/account/avatar', ['source' => 'initials'])->assertOk()->assertJsonPath('user.avatarUrl', null);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_avatar_rejects_svg_executables_fake_images_oversize_and_excessive_dimensions(): void
    {
        Storage::fake('local');
        foreach ([UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), UploadedFile::fake()->createWithContent('fake.png', '<?php echo 1;'), UploadedFile::fake()->image('large.png')->size(2049), UploadedFile::fake()->image('wide.png', 4097, 2)] as $file) {
            $this->postJson('/api/admin/account/avatar', ['source' => 'upload', 'avatar' => $file])->assertUnprocessable();
        }
        $this->assertNull($this->user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
