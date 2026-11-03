<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canary_registration_invites', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->char('token_hash', 64)->unique();
            $table->char('email_hash', 64);
            $table->unsignedBigInteger('authority_revision');
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->foreignId('consumed_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canary_registration_invites');
    }
};
