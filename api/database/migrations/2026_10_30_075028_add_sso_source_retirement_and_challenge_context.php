<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_sources', fn (Blueprint $table) => $table->timestamp('archived_at')->nullable());
        Schema::table('account_security_challenges', function (Blueprint $table): void {
            $table->string('context_hash', 64)->nullable();
            $table->index(['session_hash', 'purpose'], 'security_challenge_session_purpose');
        });
        Schema::table('developer_invitations', function (Blueprint $table): void {
            $table->boolean('can_create_projects')->nullable();
            $table->json('project_permissions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('integration_sources', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        Schema::table('account_security_challenges', function (Blueprint $table): void {
            $table->dropIndex('security_challenge_session_purpose');
            $table->dropColumn('context_hash');
        });
        Schema::table('developer_invitations', fn (Blueprint $table) => $table->dropColumn(['can_create_projects', 'project_permissions']));
    }
};
