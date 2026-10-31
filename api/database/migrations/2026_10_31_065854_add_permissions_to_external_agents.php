<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_agents', function (Blueprint $table) {
            $table->json('permissions')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Agent permissions must be retained. Use a reviewed compatible forward fix.');
    }
};
