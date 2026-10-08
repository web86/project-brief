<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspaces')) {
            Schema::create('workspaces', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('workspace_members')) {
            Schema::create('workspace_members', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->enum('role', ['owner', 'admin']);
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();
                $table->unique(['workspace_id', 'user_id']);
                $table->unique(['id', 'workspace_id']);
            });
        }
        if (! Schema::hasColumn('projects', 'workspace_id')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->foreignId('workspace_id')->nullable()->constrained()->restrictOnDelete();
                $table->unique(['id', 'workspace_id']);
            });
        }
        if (! Schema::hasTable('project_members')) {
            Schema::create('project_members', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('workspace_member_id');
                $table->timestamps();
                $table->unique(['project_id', 'workspace_member_id']);
                $table->foreign(['project_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('projects')->restrictOnDelete();
                $table->foreign(['workspace_member_id', 'workspace_id'], 'project_members_membership_workspace_foreign')->references(['id', 'workspace_id'])->on('workspace_members')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Workspace migrations are forward-only. Restore a verified backup to roll back safely.');
    }
};
