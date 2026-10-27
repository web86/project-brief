<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', fn (Blueprint $table) => $table->json('branding')->nullable());
    }

    public function down(): void
    {
        throw new RuntimeException('Branding contains company data. Keep the additive column during application rollback.');
    }
};
