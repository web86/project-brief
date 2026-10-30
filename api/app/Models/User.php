<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'preferred_locale'])]
#[Hidden(['security_recommendations_seen_at', 'security_recovery_token_hash', 'security_recovery_expires_at', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'pending_two_factor_secret', 'last_totp_step', 'recovery_codes_acknowledged_at', 'passkeys', 'password', 'remember_token', 'notification_preferences', 'notification_email', 'notification_email_resolved_at', 'pending_notification_email', 'notification_email_token_hash', 'notification_email_expires_at', 'pending_email', 'email_change_token_hash', 'email_change_expires_at', 'avatar_path', 'auth_session_version', 'platform_admin'])]
/** Legacy users.role has no authorization meaning; active workspace memberships grant access. */
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public function isWorkspaceOwner(): bool
    {
        return $this->workspaceMembers()->active()->where('role', 'owner')->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public function workspaceMembers(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function notificationPreferences(): array
    {
        return array_replace(['email' => true, 'push' => true, 'telegram' => true], $this->notification_preferences ?? []);
    }

    public function notificationEmail(): string
    {
        return $this->notification_email ?: $this->email;
    }

    protected function casts(): array
    {
        return [
            'security_recommendations_seen_at' => 'datetime',
            'security_recovery_expires_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'recovery_codes_acknowledged_at' => 'datetime',
            'last_totp_step' => 'integer',
            'platform_admin' => 'boolean',
            'email_verified_at' => 'datetime',
            'email_change_expires_at' => 'datetime',
            'auth_session_version' => 'integer',
            'password' => 'hashed',
            'notification_preferences' => 'array',
            'notification_email_resolved_at' => 'datetime',
            'notification_email_expires_at' => 'datetime',
        ];
    }
}
