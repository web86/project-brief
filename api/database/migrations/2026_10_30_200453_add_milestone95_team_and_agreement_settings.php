<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_members', fn (Blueprint $table) => $table->boolean('is_project_superadmin')->default(false));
        Schema::table('developer_invitations', function (Blueprint $table): void {
            $table->boolean('is_project_superadmin')->default(false);
            $table->boolean('project_scoped')->default(false);
        });
        Schema::table('comments', fn (Blueprint $table) => $table->boolean('team_only')->default(false)->index());
        Schema::create('workspace_agreement_revisions', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('version');
            $table->json('document');
            $table->timestamp('created_at');
        });
        // Development milestone: the former general channel becomes the private team channel.
        DB::table('comments')->whereNull('task_id')->update(['team_only' => true]);
        DB::table('workspaces')->orderBy('id')->chunkById(100, function ($workspaces): void {
            foreach ($workspaces as $workspace) {
                $settings = json_decode($workspace->administrator_agreement ?? 'null', true);
                $document = $settings['published'] ?? null;
                if ($document && isset($document['id'], $document['date'])) {
                    $document['enabledLocales'] ??= ['ru', 'en', 'tr', 'zh-CN'];
                    $document['defaultLocale'] ??= 'ru';
                    DB::table('workspace_agreement_revisions')->insertOrIgnore(['uuid' => $document['id'], 'workspace_id' => $workspace->id, 'version' => $document['date'], 'document' => json_encode($document, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Never make private chat messages public during an application rollback.
        throw new RuntimeException('Keep the additive Milestone 9.5 schema and private channel on rollback; see PRODUCTION.md.');
    }
};
