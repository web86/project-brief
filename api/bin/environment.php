<?php

namespace ProjectBrief\Deployment;

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

class DeploymentException extends \RuntimeException {}

function applicationRoot(): string
{
    return dirname(__DIR__);
}

function report(bool $passed, string $message): bool
{
    fwrite(STDOUT, ($passed ? 'PASS' : 'FAIL').': '.$message.PHP_EOL);

    return $passed;
}

function checkPrerequisites(bool $requireEnvironment): void
{
    $passed = report(PHP_VERSION_ID >= 80300, 'PHP >= 8.3 (target: 8.4)');
    foreach (['ctype', 'curl', 'dom', 'xml', 'fileinfo', 'filter', 'hash', 'iconv', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'zip'] as $extension) {
        $passed = report(extension_loaded($extension), 'Extension '.$extension) && $passed;
    }
    foreach (['vendor/autoload.php', 'bootstrap/app.php', 'artisan'] as $file) {
        $passed = report(is_file(applicationRoot().'/'.$file), $file.' exists') && $passed;
    }
    foreach (['storage', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $directory) {
        $path = applicationRoot().'/'.$directory;
        $passed = report(is_dir($path) && is_writable($path), $directory.' writable') && $passed;
    }
    if ($requireEnvironment) {
        $passed = report(is_file(applicationRoot().'/.env'), '.env exists') && $passed;
    }
    if (! $passed) {
        throw new DeploymentException('Environment check failed. Correct the FAIL items and retry.');
    }
}

/** @return array<string, string|null> */
function readEnvironment(): array
{
    require_once applicationRoot().'/vendor/autoload.php';

    try {
        $values = Dotenv::parse(file_get_contents(applicationRoot().'/.env'));
    } catch (\Throwable) {
        throw new DeploymentException('Cannot parse .env. Check its syntax without sharing credentials.');
    }

    foreach (['APP_ENV', 'APP_URL', 'FRONTEND_URL', 'DB_CONNECTION', 'DB_DATABASE'] as $key) {
        if (empty($values[$key])) {
            throw new DeploymentException('Fill '.$key.' in .env.');
        }
    }
    if ($values['DB_CONNECTION'] === 'mysql') {
        foreach (['DB_HOST', 'DB_USERNAME'] as $key) {
            if (empty($values[$key])) {
                throw new DeploymentException('Fill '.$key.' in .env.');
            }
        }
    }
    if ($values['APP_ENV'] === 'production') {
        foreach (['APP_DEBUG' => 'false', 'SESSION_SECURE_COOKIE' => 'true', 'SESSION_SAME_SITE' => 'lax', 'SESSION_DRIVER' => 'database'] as $key => $value) {
            if (($values[$key] ?? null) !== $value) {
                throw new DeploymentException('Production requires '.$key.'='.$value.'.');
            }
        }
        if (! str_starts_with($values['APP_URL'], 'https://') || $values['APP_URL'] !== $values['FRONTEND_URL']) {
            throw new DeploymentException('APP_URL and FRONTEND_URL must be the same HTTPS origin.');
        }
    }

    return $values;
}

function bootstrapApplication(): Application
{
    require_once applicationRoot().'/vendor/autoload.php';
    /** @var Application $app */
    $app = require applicationRoot().'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    return $app;
}

function checkDatabase(Application $app): void
{
    try {
        $app->make('db')->connection()->getPdo();
    } catch (\Throwable) {
        throw new DeploymentException('FAIL: Database connection. Check DB settings and access; credentials are not printed.');
    }
    report(true, 'Database connection');
}
