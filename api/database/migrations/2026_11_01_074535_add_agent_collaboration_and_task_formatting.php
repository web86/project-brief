<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['external_agents' => 'instructions', 'projects' => 'agent_instructions', 'tasks' => 'description_html'] as $name => $column) {
            if (! Schema::hasColumn($name, $column)) {
                Schema::table($name, fn (Blueprint $table) => $table->text($column)->nullable());
            }
        }
        if (! Schema::hasColumn('tasks', 'description_html_hash')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->char('description_html_hash', 64)->nullable());
        }
        if (! Schema::hasTable('agent_runs')) {
            Schema::create('agent_runs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('task_agent_assignment_id')->constrained()->cascadeOnDelete();
                $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('instruction');
                $table->json('context');
                $table->char('context_version', 64);
                $table->char('request_hash', 64);
                $table->char('key_hash', 64);
                $table->string('state', 20)->default('queued');
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('error_code', 100)->nullable();
                $table->foreignId('result_comment_id')->nullable()->constrained('comments')->nullOnDelete();
                $table->timestamps();
                $table->unique(['task_agent_assignment_id', 'key_hash']);
                $table->index(['task_agent_assignment_id', 'state', 'id']);
            });
        }
        if (! Schema::hasTable('agent_messages')) {
            Schema::create('agent_messages', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('external_agent_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('comment_id')->nullable()->constrained()->nullOnDelete();
                $table->text('text');
                $table->timestamp('created_at');
                $table->index(['agent_run_id', 'id']);
            });
        }
        if (! Schema::hasColumn('agent_reports', 'agent_run_id')) {
            Schema::table('agent_reports', fn (Blueprint $table) => $table->foreignId('agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete());
        }
    }

    public function down(): void
    {
        throw new LogicException('Agent run history requires a coordinated runtime/database rollback. Keep the additive schema.');
    }
};
