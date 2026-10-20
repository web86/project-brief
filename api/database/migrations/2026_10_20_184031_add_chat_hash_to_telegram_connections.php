<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('telegram_connections', 'chat_hash')) {
            Schema::table('telegram_connections', function (Blueprint $table): void {
                $table->string('chat_hash', 64)->nullable()->index();
            });
        }
        DB::table('telegram_connections')->whereNotNull('chat_id')->orderBy('id')->chunkById(100, function ($connections): void {
            foreach ($connections as $connection) {
                $hash = hash_hmac('sha256', Crypt::decryptString($connection->chat_id), (string) config('app.key'));
                DB::table('telegram_connections')->where('id', $connection->id)->where('chat_id', $connection->chat_id)->update(['chat_hash' => $hash]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('telegram_connections', function (Blueprint $table): void {
            $table->dropIndex(['chat_hash']);
            $table->dropColumn('chat_hash');
        });
    }
};
