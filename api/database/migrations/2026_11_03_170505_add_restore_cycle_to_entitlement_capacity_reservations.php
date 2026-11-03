<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entitlement_capacity_reservations', fn (Blueprint $table) => $table->unsignedBigInteger('restored_cycle_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('entitlement_capacity_reservations', fn (Blueprint $table) => $table->dropColumn('restored_cycle_id'));
    }
};
