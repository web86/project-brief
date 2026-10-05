<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('admin');
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('title', 160);
            $t->string('website_url', 2048)->nullable();
            $t->string('client_name')->nullable();
            $t->string('client_email')->nullable();
            $t->char('currency', 3)->default('RUB');
            $t->string('status')->default('active');
            $t->json('sections')->nullable();
            $t->timestamps();
        });
        Schema::create('project_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->char('token_hash', 64)->unique();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('title', 160);
            $t->text('location')->nullable();
            $t->text('section')->nullable();
            $t->text('description');
            $t->text('expected_result')->nullable();
            $t->string('priority')->default('normal');
            $t->string('status')->default('new');
            $t->boolean('client_approved')->default(false);
            $t->timestamp('client_approved_at')->nullable();
            $t->decimal('estimate_hours', 10, 2)->nullable();
            $t->decimal('price', 14, 2)->nullable();
            $t->text('developer_notes')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'status']);
        });
        Schema::create('comments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('task_id')->constrained()->cascadeOnDelete();
            $t->string('author_type');
            $t->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('text');
            $t->timestamps();
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('task_id')->constrained()->cascadeOnDelete();
            $t->foreignId('comment_id')->nullable()->constrained()->nullOnDelete();
            $t->string('uploaded_by_type');
            $t->string('original_name');
            $t->string('stored_name');
            $t->string('mime_type');
            $t->unsignedBigInteger('size');
            $t->string('disk');
            $t->string('path');
            $t->timestamps();
        });
        Schema::create('task_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained()->cascadeOnDelete();
            $t->string('actor_type');
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('event_type');
            $t->text('old_value')->nullable();
            $t->text('new_value')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['task_history', 'attachments', 'comments', 'tasks', 'project_access_tokens', 'projects'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
