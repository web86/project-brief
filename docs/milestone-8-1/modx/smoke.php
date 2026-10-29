<?php
/** CLI-only external smoke; no MODX account or web endpoint. JSON input comes from stdin. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/ProjectBriefRequests.php';
try {
    $action = $argv[1] ?? 'list';
    $client = new ProjectBriefRequests((string) getenv('PB_ORIGIN'), (string) getenv('PB_TOKEN'), getenv('PB_ALLOW_LOCAL_HTTP') === '1');
    $input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $key = $input['idempotencyKey'] ?? '';
    $result = match ($action) {
        'create' => $client->create($input['body'], $key),
        'get' => $client->get($input['requestId']),
        'approve' => $client->approve($input['requestId'], $input['sendInvitation'] ?? true, $key),
        'reject' => $client->reject($input['requestId'], $input['reason'] ?? null, $key),
        'list' => $client->listing($input),
        default => throw new InvalidArgumentException('Unknown smoke action.'),
    };
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof RuntimeException ? $error->getMessage().PHP_EOL : 'Invalid smoke input or configuration.'.PHP_EOL);
    exit(1);
}
