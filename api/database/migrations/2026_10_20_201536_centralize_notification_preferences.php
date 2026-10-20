<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('notification_preferences')->nullable();
            $table->string('notification_email', 254)->nullable();
            $table->timestamp('notification_email_resolved_at')->nullable();
            $table->string('pending_notification_email', 254)->nullable();
            $table->string('notification_email_token_hash', 64)->nullable();
            $table->timestamp('notification_email_expires_at')->nullable();
        });
        Schema::table('project_notification_settings', function (Blueprint $table): void {
            $table->string('delivery_mode', 16)->default('legacy');
            $table->json('event_rules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_notification_settings', fn (Blueprint $table) => $table->dropColumn(['delivery_mode', 'event_rules']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['notification_preferences', 'notification_email', 'notification_email_resolved_at', 'pending_notification_email', 'notification_email_token_hash', 'notification_email_expires_at']));
    }
};
