<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_sso_handoffs', function (Blueprint $table): void {
            $table->unsignedBigInteger('execution_epoch')->nullable();
            $table->string('execution_feature_group', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_sso_handoffs', fn (Blueprint $table) => $table->dropColumn(['execution_epoch', 'execution_feature_group']));
    }
};
