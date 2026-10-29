<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('integration_sources', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();
        });
        Schema::table('integration_tokens', function (Blueprint $table): void {
            $table->foreignId('integration_source_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('project_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('integration_token_id')->constrained()->restrictOnDelete();
            $table->string('external_id', 160);
            $table->char('external_hash', 64);
            $table->char('body_hash', 64);
            $table->string('client_name');
            $table->string('email');
            $table->string('company')->nullable();
            $table->text('website')->nullable();
            $table->string('project_name', 160);
            $table->text('description')->nullable();
            $table->string('language', 10);
            $table->string('status', 12)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('decided_by_type', 20)->nullable();
            $table->uuid('decided_by_uuid')->nullable();
            $table->string('decided_by_name')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('send_invitation')->nullable();
            $table->json('result')->nullable();
            $table->foreignId('project_provisioning_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'integration_source_id', 'external_hash'], 'project_request_external_unique');
            $table->index(['workspace_id', 'status', 'id']);
        });
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id')->nullable()->change();
            $table->foreignId('project_request_id')->nullable()->constrained()->cascadeOnDelete();
        });
        Schema::table('provisioning_operations', function (Blueprint $table): void {
            $table->string('operation_type', 40)->default('project.provision');
            $table->uuid('project_request_uuid')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('project_requests')->exists() || DB::table('integration_sources')->exists()) {
            throw new RuntimeException('Keep request decisions, sources and deduplication records during application rollback.');
        }
        Schema::table('provisioning_operations', fn (Blueprint $table) => $table->dropColumn(['operation_type', 'project_request_uuid']));
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('project_request_id');
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
        });
        Schema::dropIfExists('project_requests');
        Schema::table('integration_tokens', fn (Blueprint $table) => $table->dropConstrainedForeignId('integration_source_id'));
        Schema::dropIfExists('integration_sources');
    }
};
