<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_clients', fn (Blueprint $table) => $table->json('notification_preferences')->nullable());
        Schema::create('client_comment_reads', function (Blueprint $table): void {
            $table->foreignId('project_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_client_id', 'comment_id']);
        });
        Schema::create('telegram_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('project_client_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->text('chat_id')->nullable();
            $table->string('code_hash', 64)->nullable()->unique();
            $table->timestamp('code_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->unsignedBigInteger('update_id')->primary();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('telegram_connections');
        Schema::dropIfExists('client_comment_reads');
        Schema::table('project_clients', fn (Blueprint $table) => $table->dropColumn('notification_preferences'));
    }
};
