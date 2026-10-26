<?php

/** Verify raw ProjectBrief webhook bytes. Supply secrets through the environment, never arguments. */
$secret = getenv('WH_SECRET') ?: '';
$timestamp = $_SERVER['HTTP_X_PROJECTBRIEF_TIMESTAMP'] ?? getenv('PB_TIMESTAMP') ?: '';
$signature = $_SERVER['HTTP_X_PROJECTBRIEF_SIGNATURE'] ?? getenv('PB_SIGNATURE') ?: '';
$body = file_get_contents(PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input', false, null, 0, 131073);
$valid = $secret !== '' && preg_match('/^[0-9]{10,12}$/D', $timestamp) && abs(time() - (int) $timestamp) <= 300 && strlen($body) <= 131072
    && hash_equals('sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature);
if (! $valid) {
    http_response_code(401);
    fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'w'), "Invalid signature or timestamp.\n");
    exit(1);
}
try {
    $event = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($event) || ! is_string($event['id'] ?? null) || ($event['version'] ?? null) !== 1) {
        throw new RuntimeException('Invalid event.');
    }
} catch (Throwable) {
    http_response_code(400);
    exit(1);
}
// Persist event.id uniquely and queue side effects transactionally here before returning 2xx.
echo "Verified.\n";
