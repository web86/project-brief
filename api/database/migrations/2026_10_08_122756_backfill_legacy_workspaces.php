<?php

use App\Services\LegacyWorkspaceBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        LegacyWorkspaceBackfill::run();
    }

    public function down(): void
    {
        throw new RuntimeException('Legacy tenant associations preserve production access. Restore a verified backup to roll back.');
    }
};
