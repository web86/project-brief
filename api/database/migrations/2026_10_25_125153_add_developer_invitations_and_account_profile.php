<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('developer_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->string('email', 254);
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->json('project_ids');
            $table->string('locale', 2)->default('ru');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'email']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pending_email', 254)->nullable();
            $table->string('email_change_token_hash', 64)->nullable()->unique();
            $table->timestamp('email_change_expires_at')->nullable();
            $table->string('avatar_source', 16)->default('initials');
            $table->string('avatar_path')->nullable();
            $table->unsignedInteger('auth_session_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developer_invitations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['pending_email', 'email_change_token_hash', 'email_change_expires_at', 'avatar_source', 'avatar_path', 'auth_session_version']));
    }
};
