<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_members', fn (Blueprint $table) => $table->string('display_name')->nullable());
        Schema::table('developer_invitations', fn (Blueprint $table) => $table->timestamp('sent_at')->nullable());
        DB::table('developer_invitations')->update(['sent_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('workspace_members', fn (Blueprint $table) => $table->dropColumn('display_name'));
        Schema::table('developer_invitations', fn (Blueprint $table) => $table->dropColumn('sent_at'));
    }
};
