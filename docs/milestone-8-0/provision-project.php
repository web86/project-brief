<?php

declare(strict_types=1);

/**
 * Server-side helper for a WordPress/MODX order or registration hook.
 * Store $externalId, the exact payload/key and returned IDs in your existing order record.
 * Load $token from private server configuration. Never expose it to a browser or log it.
 * Call only after the external order transaction commits.
 *
 * @return array{operationId:string, projectId:string, clientId:string, projectClientId:string, projectUrl:string, invitation:string, created:bool}
 */
function provisionProject(string $baseUrl, #[SensitiveParameter] string $token, string $externalId, array $client, array $project, bool $sendInvitation = true, ?string $caFile = null): array
{
    if (! extension_loaded('curl') || ! preg_match('/^pb_[a-f0-9]{64}$/D', $token)) {
        throw new RuntimeException('Configure the server API token and PHP cURL.');
    }
    $url = parse_url($baseUrl);
    if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || ! in_array($url['path'] ?? '', ['', '/'], true)) {
        throw new InvalidArgumentException('Use the trusted HTTPS origin of Project Brief.');
    }
    $json = json_encode(compact('externalId', 'client', 'project', 'sendInvitation'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $key = 'provision-'.hash('sha256', $externalId);
    $endpoint = rtrim($baseUrl, '/').'/api/v1/projects/provision';
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $retryAfter = null;
        $handle = curl_init($endpoint);
        curl_setopt_array($handle, [
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                if (preg_match('/^Retry-After:\s*(\d+)\s*$/i', trim($header), $match)) {
                    $retryAfter = min(30, (int) $match[1]);
                }

                return strlen($header);
            },
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Content-Type: application/json', 'Accept: application/json', 'Idempotency-Key: '.$key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($caFile !== null) {
            curl_setopt($handle, CURLOPT_CAINFO, $caFile);
        }
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (is_string($body) && in_array($status, [200, 201], true)) {
            $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['data'] ?? null;
            if (! is_array($result) || ! is_string($result['projectId'] ?? null) || ! is_string($result['projectUrl'] ?? null) || ! str_starts_with($result['projectUrl'], rtrim($baseUrl, '/').'/project/')) {
                throw new RuntimeException('Unexpected Project Brief response.');
            }

            return $result;
        }
        if ($attempt < 2 && ($body === false || $status === 429 || $status >= 500)) {
            // Retry exactly the same operation; do not log the request, token or response.
            sleep(max($attempt + 1, $retryAfter ?? 0));
            continue;
        }
        throw new RuntimeException('Project provisioning failed (HTTP '.$status.'). Preserve this order and retry or reconcile it.');
    }
    throw new RuntimeException('Provisioning result unavailable.');
}

// In your existing server hook, pass the stored order ID and immutable order snapshot:
// $result = provisionProject($trustedOrigin, $privateToken, 'order-'.$orderId,
//     ['name' => $customerName, 'email' => $customerEmail],
//     ['name' => $projectName, 'language' => 'ru']);
// Save $result['projectId'] and $result['projectUrl']; HTML-escape the URL when rendering the button.
