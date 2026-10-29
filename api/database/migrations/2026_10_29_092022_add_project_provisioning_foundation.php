<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_tokens', function (Blueprint $table): void {
            $table->boolean('provisioning_enabled')->default(false);
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->string('language', 10)->nullable();
        });
        Schema::create('workspace_clients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->char('email_hash', 64);
            $table->string('company')->nullable();
            $table->text('website')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'email_hash']);
        });
        Schema::table('project_clients', function (Blueprint $table): void {
            $table->foreignId('workspace_client_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::create('project_provisionings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_token_id')->constrained()->cascadeOnDelete();
            $table->char('external_hash', 64);
            $table->char('body_hash', 64);
            $table->foreignId('workspace_client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invitation_status', 30);
            $table->timestamps();
            $table->unique(['workspace_id', 'integration_token_id', 'external_hash'], 'provisioning_external_unique');
        });
        Schema::create('provisioning_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_token_id')->constrained()->cascadeOnDelete();
            $table->uuid('provisioning_uuid')->nullable();
            $table->string('outcome', 20);
            $table->unsignedSmallInteger('http_status');
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('workspace_clients')->exists() || DB::table('project_provisionings')->exists()) {
            throw new RuntimeException('Provisioning contains client identities and deduplication records. Keep the additive schema during application rollback.');
        }
        Schema::dropIfExists('provisioning_operations');
        Schema::dropIfExists('project_provisionings');
        Schema::table('project_clients', fn (Blueprint $table) => $table->dropConstrainedForeignId('workspace_client_id'));
        Schema::dropIfExists('workspace_clients');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['description', 'language']));
        Schema::table('integration_tokens', fn (Blueprint $table) => $table->dropColumn('provisioning_enabled'));
    }
};
