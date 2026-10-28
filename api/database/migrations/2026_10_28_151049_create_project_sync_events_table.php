<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_sync_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->timestamp('created_at')->index();
            $table->index(['workspace_id', 'id']);
            $table->index(['project_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_sync_events');
    }
};
