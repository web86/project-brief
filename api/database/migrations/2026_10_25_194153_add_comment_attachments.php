<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('task_id')->nullable()->change();
            $table->string('thumbnail_path')->nullable();
        });
        Schema::table('comments', function (Blueprint $table) {
            $table->char('submission_key', 64)->nullable()->unique();
            $table->char('submission_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('comments')->whereNotNull('submission_key')->exists() || DB::table('attachments')->whereNotNull('thumbnail_path')->exists()) {
            throw new RuntimeException('Comment attachments exist. Keep this additive schema when rolling back application code.');
        }
        // Keep nullable task_id: reverting it would destroy general discussion attachments.
        Schema::table('comments', function (Blueprint $table) {
            $table->dropUnique(['submission_key']);
            $table->dropColumn(['submission_key', 'submission_fingerprint']);
        });
        Schema::table('attachments', fn (Blueprint $table) => $table->dropColumn('thumbnail_path'));
    }
};
