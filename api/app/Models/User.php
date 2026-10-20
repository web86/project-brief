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

#[Fillable(['name', 'email', 'password', 'preferred_locale'])]
#[Hidden(['password', 'remember_token', 'notification_preferences', 'notification_email', 'notification_email_resolved_at', 'pending_notification_email', 'notification_email_token_hash', 'notification_email_expires_at'])]
/** Legacy users.role has no authorization meaning; active workspace memberships grant access. */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
            'notification_email_resolved_at' => 'datetime',
            'notification_email_expires_at' => 'datetime',
        ];
    }
}
