<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('integration_tokens', 'agent_shared')) {
            Schema::table('integration_tokens', fn (Blueprint $table) => $table->boolean('agent_shared')->default(false));
        }
        if (! Schema::hasColumn('agent_operations', 'claimed_agent_uuid')) {
            Schema::table('agent_operations', function (Blueprint $table): void {
                $table->unsignedBigInteger('external_agent_id')->nullable()->change();
                $table->uuid('claimed_agent_uuid')->nullable();
            });
        }
        if (! Schema::hasTable('agent_credential_links')) {
            Schema::create('agent_credential_links', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('integration_token_id')->constrained()->restrictOnDelete();
                $table->foreignId('external_agent_id')->unique()->constrained()->restrictOnDelete();
                $table->timestamp('created_at');
                $table->index(['integration_token_id', 'external_agent_id'], 'agent_credential_token_agent_idx');
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Keep additive credential bindings and audit history during a runtime rollback.');
    }
};
