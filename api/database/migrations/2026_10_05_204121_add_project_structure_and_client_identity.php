<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_sections')) {
            Schema::create('project_sections', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->text('name');
                $table->unsignedInteger('position');
                $table->timestamps();
                $table->index(['project_id', 'position']);
            });
        }
        if (! Schema::hasTable('project_clients')) {
            Schema::create('project_clients', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('email')->nullable();
                $table->boolean('active')->default(true);
                $table->string('preferred_locale', 2)->nullable();
                $table->timestamps();
                $table->index(['project_id', 'active']);
            });
        }
        if (! Schema::hasColumn('tasks', 'project_section_id')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->foreignId('project_section_id')->nullable()->constrained('project_sections')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('tasks', 'position')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->unsignedInteger('position')->nullable());
        }
        if (! Schema::hasIndex('tasks', ['project_section_id', 'position'])) {
            Schema::table('tasks', fn (Blueprint $table) => $table->index(['project_section_id', 'position']));
        }
        foreach (['project_access_tokens', 'comments', 'task_history', 'attachments'] as $name) {
            if (! Schema::hasColumn($name, 'project_client_id')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->foreignId('project_client_id')->nullable()->constrained('project_clients')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        // Forward-only while production uses these associations. Never remove data on rollback.
        throw new RuntimeException('This additive production migration is forward-only. Restore a verified backup if rollback is required.');
    }
};
