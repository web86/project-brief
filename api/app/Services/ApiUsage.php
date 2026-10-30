<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pdo\Mysql;

class ApiUsage
{
    public function connection(): Connection
    {
        $default = config('database.default');
        if (! in_array(config("database.connections.$default.driver"), ['mysql', 'mariadb'], true)) {
            return DB::connection();
        }
        if (! config('database.connections.platform_metrics')) {
            $settings = config("database.connections.$default");
            $settings['options'][\PDO::ATTR_TIMEOUT] = 1;
            $settings['options'][Mysql::ATTR_INIT_COMMAND] = 'SET SESSION innodb_lock_wait_timeout=1';
            config(['database.connections.platform_metrics' => $settings]);
        }

        return DB::connection('platform_metrics');
    }

    public function record(Request $request, int $status, int $durationUs): void
    {
        $token = $request->attributes->get('integration_token');
        $route = $request->route()?->uri();
        $route = $route && str_starts_with($route, 'api/v1/') && ! str_contains($route, '{path}') ? '/'.$route : '/api/v1/{unmatched}';
        $method = in_array($request->method(), ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true) ? $request->method() : 'OTHER';
        $dimensions = ['hour' => $request->attributes->get('usage.hour', now('UTC')->startOfHour()->format('Y-m-d H:i:s')), 'workspace_id' => $token ? (int) $token->workspace_id : null, 'integration_source_id' => $token?->integration_source_id !== null ? (int) $token->integration_source_id : null, 'integration_token_id' => $token ? (int) $token->id : null, 'method' => $method, 'route' => $route];
        $row = ['dimension_key' => hash('sha256', json_encode($dimensions, JSON_THROW_ON_ERROR)), ...$dimensions, 'requests' => 1, 'responses_2xx' => (int) ($status >= 200 && $status < 300), 'responses_4xx' => (int) ($status >= 400 && $status < 500), 'responses_5xx' => (int) ($status >= 500), 'responses_429' => (int) ($status === 429), 'duration_us' => $durationUs, 'max_duration_us' => $durationUs];
        $db = $this->connection();
        $updates = [];
        foreach (['requests', 'responses_2xx', 'responses_4xx', 'responses_5xx', 'responses_429', 'duration_us'] as $column) {
            $updates[$column] = DB::raw($column.' + '.(int) $row[$column]);
        }
        $updates['max_duration_us'] = DB::raw(($db->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST').'(max_duration_us, '.(int) $durationUs.')');
        $db->table('api_usage_hours')->upsert($row, ['dimension_key'], $updates);
    }
}
