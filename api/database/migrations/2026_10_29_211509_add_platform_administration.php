<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('platform_admin')->default(false));
        Schema::create('api_usage_hours', function (Blueprint $table): void {
            $table->char('dimension_key', 64)->primary();
            $table->timestamp('hour')->index();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('integration_source_id')->nullable();
            $table->unsignedBigInteger('integration_token_id')->nullable();
            $table->string('method', 8);
            $table->string('route', 200);
            foreach (['requests', 'responses_2xx', 'responses_4xx', 'responses_5xx', 'responses_429', 'duration_us', 'max_duration_us'] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
            $table->index(['workspace_id', 'hour']);
        });
        foreach (['webhook_events', 'webhook_deliveries', 'webhook_attempts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->index('created_at', $name.'_created_at_index'));
        }
        Schema::create('platform_audit', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('created_at')->index();
            $table->unsignedBigInteger('administrator_id')->nullable();
            $table->string('action', 60);
            $table->string('object_type', 24);
            $table->string('object_id', 40)->nullable();
            $table->string('result', 24);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Platform audit and grants must survive application rollback. Restore a reviewed backup to remove this migration.');
    }
};
