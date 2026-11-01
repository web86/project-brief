<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_agents', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('description', 1000)->nullable();
            $t->string('kind', 20)->default('ai');
            $t->boolean('active')->default(true);
            $t->timestamp('last_success_at')->nullable();
            $t->unsignedBigInteger('successful_requests')->default(0);
            $t->unsignedBigInteger('failed_requests')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['id', 'workspace_id']);
        });
        Schema::create('agent_project_grants', function (Blueprint $t): void {
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('external_agent_id');
            $t->unsignedBigInteger('project_id');
            $t->primary(['external_agent_id', 'project_id']);
            $t->foreign(['external_agent_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('external_agents')->cascadeOnDelete();
            $t->foreign(['project_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('projects')->cascadeOnDelete();
        });
        Schema::table('project_members', function (Blueprint $t): void {
            $t->unsignedBigInteger('workspace_member_id')->nullable()->change();
            $t->unsignedBigInteger('external_agent_id')->nullable();
            $t->unique(['project_id', 'external_agent_id']);
            $t->foreign(['external_agent_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('external_agents')->cascadeOnDelete();
        });
        foreach (['integration_tokens', 'webhook_endpoints'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->foreignId('external_agent_id')->nullable()->constrained('external_agents')->restrictOnDelete();
            });
        }
        Schema::table('comments', fn (Blueprint $t) => $t->foreignId('external_agent_id')->nullable()->constrained()->restrictOnDelete());
        Schema::create('task_agent_assignments', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('task_id')->constrained()->cascadeOnDelete();
            $t->foreignId('external_agent_id')->constrained()->restrictOnDelete();
            $t->string('state', 20)->default('pending');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['external_agent_id', 'revoked_at', 'id'], 'agent_assignment_active');
            $t->index(['task_id', 'external_agent_id']);
        });
        Schema::create('agent_reports', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('task_agent_assignment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('comment_id')->nullable()->constrained()->nullOnDelete();
            $t->string('state', 20);
            $t->string('pull_request_url', 2048)->nullable();
            $t->string('result_url', 2048)->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('agent_operations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('external_agent_id')->constrained()->restrictOnDelete();
            $t->foreignId('integration_token_id')->nullable()->constrained()->nullOnDelete();
            $t->uuid('project_uuid')->nullable();
            $t->uuid('task_uuid')->nullable();
            $t->uuid('assignment_uuid')->nullable();
            $t->string('operation', 100);
            $t->unsignedSmallInteger('http_status');
            $t->timestamp('created_at')->index();
            $t->index(['external_agent_id', 'id']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Agent identities and results require a coordinated runtime/database rollback.');
    }
};
