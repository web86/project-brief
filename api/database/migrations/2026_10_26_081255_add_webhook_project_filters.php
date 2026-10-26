<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->string('project_filter', 16)->default('all');
            $table->unique(['id', 'workspace_id'], 'webhook_endpoint_workspace_unique');
        });
        Schema::create('webhook_endpoint_projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('webhook_endpoint_id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('project_id');
            $table->primary(['webhook_endpoint_id', 'project_id'], 'webhook_endpoint_project_primary');
            $table->foreign(['webhook_endpoint_id', 'workspace_id'], 'webhook_project_endpoint_fk')->references(['id', 'workspace_id'])->on('webhook_endpoints')->cascadeOnDelete();
            $table->foreign(['project_id', 'workspace_id'], 'webhook_project_project_fk')->references(['id', 'workspace_id'])->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoint_projects');
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->dropUnique('webhook_endpoint_workspace_unique');
            $table->dropColumn('project_filter');
        });
    }
};
