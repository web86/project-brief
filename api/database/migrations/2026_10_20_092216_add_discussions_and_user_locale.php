<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('preferred_locale', 2)->nullable());
        Schema::table('comments', function (Blueprint $table): void {
            $table->unsignedBigInteger('task_id')->nullable()->change();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['created_at', 'id'], 'comments_chronology_index');
        });
        Schema::create('comment_reads', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'comment_id']);
        });
        $this->preserveReadComments();
    }

    private function preserveReadComments(): void
    {
        DB::table('project_reads')->join('tasks', 'tasks.project_id', '=', 'project_reads.project_id')
            ->join('task_history', 'task_history.task_id', '=', 'tasks.id')
            ->whereColumn('task_history.id', '<=', 'project_reads.last_seen_history_id')
            ->where('task_history.event_type', 'comment')->where('task_history.actor_type', 'client')
            ->select(['project_reads.user_id', 'task_history.task_id', 'task_history.meta'])
            ->orderBy('task_history.id')->orderBy('project_reads.user_id')->chunk(500, function ($histories): void {
                $candidates = $histories->map(function ($history): array {
                    $meta = json_decode($history->meta ?? '{}', true);

                    return ['user_id' => $history->user_id, 'task_id' => $history->task_id, 'comment_id' => $meta['commentId'] ?? null];
                });
                $comments = DB::table('comments')->whereIn('id', $candidates->pluck('comment_id')->filter())
                    ->where('author_type', 'client')->pluck('task_id', 'id');
                $reads = $candidates->filter(fn (array $row): bool => $row['comment_id'] !== null && (int) $comments->get($row['comment_id'], 0) === (int) $row['task_id'])
                    ->map(fn (array $row): array => ['user_id' => $row['user_id'], 'comment_id' => $row['comment_id']])->all();
                if ($reads !== []) {
                    DB::table('comment_reads')->insertOrIgnore($reads);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_reads');
        DB::table('comments')->whereNull('task_id')->delete();
        Schema::table('comments', function (Blueprint $table): void {
            $table->dropIndex('comments_chronology_index');
            $table->dropConstrainedForeignId('project_id');
            $table->unsignedBigInteger('task_id')->nullable(false)->change();
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('preferred_locale'));
    }
};
