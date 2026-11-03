<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password_login_enabled')) {
            Schema::table('users', fn (Blueprint $t) => $t->boolean('password_login_enabled')->default(true));
        }
        if (! Schema::hasTable('account_onboardings')) {
            Schema::create('account_onboardings', function (Blueprint $t): void {
                $t->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
                $t->uuid('uuid')->unique();
                $t->foreignId('workspace_id')->nullable()->unique()->constrained()->restrictOnDelete();
                $t->string('state', 32)->default('email_verification_pending');
                $t->char('verification_hash', 64)->nullable()->unique();
                $t->char('consumed_hash', 64)->nullable();
                $t->char('email_hash', 64)->nullable();
                $t->dateTime('expires_at', 6)->nullable();
                $t->dateTime('sent_at', 6)->nullable();
                $t->dateTime('verified_at', 6)->nullable();
                $t->unsignedBigInteger('revision')->default(1);
                $t->timestamps(6);
                $t->index(['state', 'expires_at']);
            });
        }
        if (! Schema::hasTable('social_auth_attempts')) {
            Schema::create('social_auth_attempts', function (Blueprint $t): void {
                $t->char('state_hash', 64)->primary();
                $t->string('provider', 16);
                $t->string('intent', 16);
                $t->string('locale', 10)->default('en');
                $t->char('session_hash', 64);
                $t->foreignId('actor_user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $t->unsignedBigInteger('session_version')->nullable();
                $t->text('verifier_cipher');
                $t->char('nonce_hash', 64);
                $t->text('facts_cipher')->nullable();
                $t->text('invitation_cipher')->nullable();
                $t->string('state', 24)->default('pending');
                $t->dateTime('expires_at', 6);
                $t->dateTime('created_at', 6);
                $t->dateTime('consumed_at', 6)->nullable();
                $t->index(['state', 'expires_at']);
            });
        }
        foreach ([
            ['account_onboardings', 'account_onboardings_uuid_unique', ['uuid'], true],
            ['account_onboardings', 'account_onboardings_workspace_id_unique', ['workspace_id'], true],
            ['account_onboardings', 'account_onboardings_verification_hash_unique', ['verification_hash'], true],
            ['account_onboardings', 'account_onboardings_state_expires_at_index', ['state', 'expires_at'], false],
            ['social_auth_attempts', 'social_auth_attempts_state_expires_at_index', ['state', 'expires_at'], false],
        ] as [$table, $name, $columns, $unique]) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($columns, $name) : $t->index($columns, $name));
            }
        }
        foreach ([['account_onboardings', 'user_id', 'users', 'cascade'], ['account_onboardings', 'workspace_id', 'workspaces', 'restrict'], ['social_auth_attempts', 'actor_user_id', 'users', 'cascade']] as [$table, $column, $target, $onDelete]) {
            $name = $table.'_'.$column.'_foreign';
            if (! collect(Schema::getForeignKeys($table))->contains(fn ($fk) => $fk['columns'] === [$column] && $fk['foreign_table'] === $target)) {
                Schema::table($table, fn (Blueprint $t) => $t->foreign($column, $name)->references('id')->on($target)->onDelete($onDelete));
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve onboarding and durable identity history during code rollback.');
    }
};
