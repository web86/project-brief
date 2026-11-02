<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoEnvironment
{
    public static function enabled(): bool
    {
        return config('demo.enabled') === true;
    }

    public static function readOnly(): bool
    {
        return self::enabled() && config('demo.read_only') === true;
    }

    /** Fail closed before authentication, seeding, resetting or serving a demo. */
    public static function assertIsolated(): void
    {
        if (! self::enabled() || ! app()->environment(['local', 'demo', 'testing'])) {
            throw new RuntimeException('Demo requires an explicitly enabled isolated local installation.');
        }
        $connection = DB::connection();
        if (app()->environment('testing') && config('demo.test_fixture') === true && (($connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:') || ($connection->getDriverName() === 'mysql' && $connection->getDatabaseName() === 'projectbrief_agent_ux_full_qa' && $connection->getConfig('host') === '127.0.0.1'))) {
            return;
        }
        $marker = json_decode(@file_get_contents(base_path('demo-installation.json')) ?: '{}', true);
        if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== 'projectbrief_demo'
            || $connection->getConfig('host') !== '127.0.0.1'
            || ! in_array($connection->getConfig('username'), ['demo_writer', 'demo_reader'], true)
            || ! config('demo.installation_id') || ($marker['installationId'] ?? null) !== config('demo.installation_id')
            || ($marker['database'] ?? null) !== 'projectbrief_demo'
            || ! str_contains(storage_path(), 'demo-runtime')) {
            throw new RuntimeException('Demo database/storage installation marker does not match.');
        }
    }

    public static function blocked(): JsonResponse
    {
        return response()->json(['code' => 'demo_view_only', 'message' => 'Demo · View only. Changes are unavailable.'], 403)->header('Cache-Control', 'no-store');
    }
}
