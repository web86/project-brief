<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('projects')->whereNull('workspace_id')->exists()) {
            throw new RuntimeException('Workspace backfill must complete before enforcing tenant ownership.');
        }
        Schema::table('projects', fn (Blueprint $table) => $table->unsignedBigInteger('workspace_id')->nullable(false)->change());
        // Retain legacy values; new accounts gain privileges only through explicit membership.
        Schema::table('users', fn (Blueprint $table) => $table->string('role')->default('user')->change());
    }

    public function down(): void
    {
        throw new RuntimeException('Tenant ownership is mandatory. Restore a verified backup to roll back.');
    }
};
