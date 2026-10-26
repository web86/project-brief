<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_tokens', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->char('secret_hash', 64)->unique();
            $t->text('scopes');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('integration_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->char('key_hash', 64);
            $t->char('body_hash', 64);
            $t->longText('response');
            $t->unsignedSmallInteger('status');
            $t->timestamp('created_at');
            $t->unique(['workspace_id', 'key_hash']);
        });
        Schema::create('integration_project_links', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('source_system', 64);
            $t->string('external_id', 160);
            $t->char('identity_hash', 64);
            $t->unique(['workspace_id', 'identity_hash']);
        });
        Schema::create('webhook_endpoints', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->text('url');
            $t->text('events');
            $t->boolean('enabled')->default(true);
            $t->text('secret');
            $t->unsignedInteger('secret_version')->default(1);
            $t->timestamps();
        });
        Schema::create('webhook_events', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('type', 40);
            $t->longText('body');
            $t->timestamp('created_at');
        });
        Schema::create('webhook_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $t->foreignId('webhook_event_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('secret_version');
            $t->string('status', 20)->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('available_at');
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('error_code', 50)->nullable();
            $t->timestamps();
            $t->unique(['webhook_endpoint_id', 'webhook_event_id']);
            $t->index(['status', 'available_at']);
        });
        Schema::create('webhook_attempts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('webhook_delivery_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('attempt');
            $t->string('status', 20);
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('error_code', 50)->nullable();
            $t->unsignedInteger('duration_ms');
            $t->timestamp('created_at');
        });
        Schema::create('integration_locks', function (Blueprint $t): void {
            $t->string('name', 30)->primary();
            $t->string('owner', 40);
            $t->timestamp('expires_at');
        });
    }

    public function down(): void
    {
        foreach (['integration_locks', 'webhook_attempts', 'webhook_deliveries', 'webhook_events', 'webhook_endpoints', 'integration_project_links', 'integration_requests', 'integration_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
