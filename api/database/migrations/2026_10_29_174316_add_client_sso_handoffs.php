<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_sources', function (Blueprint $table): void {
            $table->boolean('sso_enabled')->default(false);
            $table->string('sso_origin', 255)->nullable();
        });
        Schema::create('integration_source_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unique(['integration_source_id', 'project_id'], 'sso_source_project_unique');
        });
        Schema::create('client_sso_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_source_id')->constrained()->cascadeOnDelete();
            $table->char('external_hash', 64);
            $table->char('email_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['workspace_id', 'integration_source_id', 'external_hash'], 'sso_external_unique');
            $table->unique(['workspace_id', 'integration_source_id', 'email_hash'], 'sso_email_unique');
        });
        Schema::create('client_sso_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_sso_binding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_client_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'expires_at']);
        });
        Schema::table('project_access_tokens', function (Blueprint $table): void {
            // Retain the marker if a handoff is deleted: a missing row must invalidate SSO, never turn it into an ordinary link.
            $table->unsignedBigInteger('client_sso_handoff_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (DB::table('client_sso_bindings')->exists() || DB::table('client_sso_handoffs')->exists()) {
            throw new RuntimeException('Keep SSO bindings and revocation markers during application rollback. Disable SSO before rolling back code.');
        }
        Schema::table('project_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['client_sso_handoff_id']);
            $table->dropColumn('client_sso_handoff_id');
        });
        Schema::dropIfExists('client_sso_handoffs');
        Schema::dropIfExists('client_sso_bindings');
        Schema::dropIfExists('integration_source_projects');
        Schema::table('integration_sources', fn (Blueprint $table) => $table->dropColumn(['sso_enabled', 'sso_origin']));
    }
};
