<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', fn (Blueprint $table) => $table->json('administrator_agreement')->nullable());
        Schema::table('workspace_members', fn (Blueprint $table) => $table->uuid('agreement_requirement')->nullable());
        Schema::create('workspace_agreement_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('requirement_id');
            $table->uuid('revision_id');
            $table->date('version');
            $table->string('locale', 5);
            $table->timestamp('accepted_at');
            $table->unique(['workspace_member_id', 'requirement_id'], 'workspace_agreement_acceptance_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_agreement_acceptances');
        Schema::table('workspace_members', fn (Blueprint $table) => $table->dropColumn('agreement_requirement'));
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('administrator_agreement'));
    }
};
