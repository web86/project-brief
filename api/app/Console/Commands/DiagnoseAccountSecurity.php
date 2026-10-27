<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Laravel\Passkeys\Passkeys;
use Throwable;
use Webauthn\Credential;

class DiagnoseAccountSecurity extends Command
{
    protected $signature = 'account:security-diagnostics';

    protected $description = 'Read-only, secret-free Passkey configuration and schema diagnostics';

    public function handle(): int
    {
        $schema = [];
        foreach (['passkeys' => ['id', 'user_id', 'name', 'credential_id', 'credential', 'last_used_at'], 'account_security_challenges' => ['id', 'user_id', 'session_version', 'session_hash', 'purpose', 'options', 'expires_at'], 'users' => ['auth_session_version', 'two_factor_secret', 'last_totp_step', 'recovery_codes_acknowledged_at', 'security_recommendations_seen_at']] as $table => $columns) {
            try {
                $schema[$table] = Schema::hasColumns($table, $columns);
            } catch (Throwable) {
                $schema[$table] = false;
            }
        }
        $origin = Passkeys::allowedOrigins();
        $runtimeBase = class_exists(Credential::class);
        $this->line(json_encode([
            'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'utc' => gmdate('c'),
            'extensions' => array_combine(['openssl', 'mbstring', 'json', 'pdo'], array_map(extension_loaded(...), ['openssl', 'mbstring', 'json', 'pdo'])),
            'config_cached' => app()->configurationIsCached(),
            'origin_matches_expected' => $origin === ['https://brief.web86.site'],
            'rp_matches_expected' => Passkeys::relyingPartyId() === 'brief.web86.site',
            'transport_origin_matches_validator' => config('fortify.passkeys.allowed_origins') === $origin,
            'session' => ['driver' => config('session.driver'), 'secure' => config('session.secure'), 'http_only' => config('session.http_only'), 'same_site' => config('session.same_site'), 'domain_is_host_only' => ! config('session.domain')],
            'database_driver' => config('database.default'), 'schema' => $schema,
            'runtime' => ['webauthn_base' => $runtimeBase],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return in_array(false, $schema, true) || ! $runtimeBase ? self::FAILURE : self::SUCCESS;
    }
}
