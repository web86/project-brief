<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // A watermark must survive deletion of the history row it originally referenced.
            $table->unsignedBigInteger('last_seen_history_id')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'project_id']);
        });
        Schema::table('task_history', fn (Blueprint $table) => $table->index(['task_id', 'actor_type', 'event_type', 'id'], 'task_history_client_activity_index'));
    }

    public function down(): void
    {
        throw new RuntimeException('Personal read-state migration is forward-only. Restore a reviewed backup to roll back.');
    }
};
