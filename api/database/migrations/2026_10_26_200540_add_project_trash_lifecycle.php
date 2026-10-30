<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->softDeletes();
            $table->string('status_before_trash')->nullable();
            $table->index(['workspace_id', 'deleted_at']);
        });
        Schema::create('project_file_deletions', function (Blueprint $table): void {
            $table->id();
            $table->string('disk');
            $table->text('path');
        });
    }

    public function down(): void
    {
        if (DB::table('projects')->whereNotNull('deleted_at')->exists() || DB::table('project_file_deletions')->exists()) {
            throw new RuntimeException('Restore or purge trashed projects and finish file cleanup before rollback.');
        }
        if (DB::getDriverName() === 'mysql' && ! Schema::hasIndex('projects', 'projects_workspace_id_rollback_index')) {
            // MariaDB may choose the composite index to back the workspace foreign key.
            Schema::table('projects', fn (Blueprint $table) => $table->index('workspace_id', 'projects_workspace_id_rollback_index'));
        }
        Schema::dropIfExists('project_file_deletions');
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'deleted_at']);
            $table->dropSoftDeletes();
            $table->dropColumn('status_before_trash');
        });
    }
};
