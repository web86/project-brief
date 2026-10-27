<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->text('pending_two_factor_secret')->nullable();
            $table->unsignedBigInteger('last_totp_step')->nullable();
            $table->timestamp('recovery_codes_acknowledged_at')->nullable();
            $table->string('security_recovery_token_hash', 64)->nullable();
            $table->timestamp('security_recovery_expires_at')->nullable();
        });
        Schema::create('passkeys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
        Schema::create('account_security_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('session_version')->nullable();
            $table->string('session_hash', 64);
            $table->string('purpose', 40);
            $table->text('options');
            $table->timestamp('expires_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_security_challenges');
        Schema::dropIfExists('passkeys');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'pending_two_factor_secret', 'last_totp_step', 'recovery_codes_acknowledged_at', 'security_recovery_token_hash', 'security_recovery_expires_at']);
        });
    }
};
