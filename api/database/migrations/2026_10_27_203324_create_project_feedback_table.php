<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_client_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('text')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('shown_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('notification_delivery_id')->nullable()->constrained('notification_deliveries')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'project_client_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Feedback contains production data. Restore a reviewed backup instead of dropping it.');
    }
};
