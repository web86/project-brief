<?php

use App\Services\LegacyProjectStructure;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        LegacyProjectStructure::run();
    }

    public function down(): void
    {
        // Backfill deliberately preserves all legacy fields; undoing associations would lose data.
    }
};
