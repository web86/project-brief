<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('notification_email')->nullable();
            $table->json('events')->nullable();
            $table->timestamps();
        });
        DB::table('projects')->orderBy('id')->chunkById(100, function ($projects): void {
            DB::table('project_notification_settings')->insert($projects->map(fn ($project) => ['project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()])->all());
        });
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->text('public_key');
            $table->text('auth_token');
            $table->string('content_encoding', 20)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->char('dedup_key', 64)->unique();
            $table->string('event', 80);
            $table->string('channel', 10);
            $table->string('recipient_type', 10);
            $table->unsignedBigInteger('recipient_id');
            $table->string('status', 12);
            $table->string('error_code', 40)->nullable();
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Notification migrations are additive. Rollback requires a reviewed data backup.');
    }
};
