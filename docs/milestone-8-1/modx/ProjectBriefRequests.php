<?php
/** Server-side helper. Keep credentials outside the document root. No MODX dependency. */
final class ProjectBriefRequests
{
    private string $origin;
    private string $token;

    public function __construct(string $origin, string $token, bool $allowLocalHttp = false)
    {
        $parts = parse_url($origin);
        $local = $allowLocalHttp && PHP_SAPI === 'cli' && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true);
        if ((!$local && ($parts['scheme'] ?? '') !== 'https') || empty($parts['host']) || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['query']) || isset($parts['fragment']) || !preg_match('/^pb_[a-f0-9]{64}$/D', $token)) {
            throw new InvalidArgumentException('Invalid Project Brief configuration.');
        }
        $this->origin = rtrim($origin, '/');
        $this->token = $token;
    }

    public function create(array $input, string $key): array
    {
        return $this->request('POST', '/project-requests', $input, $key);
    }

    public function listing(array $filters = []): array
    {
        $filters = array_intersect_key($filters, array_flip(['page', 'perPage', 'status', 'search', 'sort']));
        return $this->request('GET', '/project-requests?'.http_build_query($filters));
    }

    public function get(string $uuid): array
    {
        return $this->request('GET', '/project-requests/'.$this->uuid($uuid));
    }

    public function approve(string $uuid, bool $sendInvitation, string $key): array
    {
        return $this->request('POST', '/project-requests/'.$this->uuid($uuid).'/approve', ['sendInvitation' => $sendInvitation], $key);
    }

    public function reject(string $uuid, ?string $reason, string $key): array
    {
        return $this->request('POST', '/project-requests/'.$this->uuid($uuid).'/reject', ['reason' => $reason], $key);
    }

    private function uuid(string $value): string
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $value)) {
            throw new InvalidArgumentException('Invalid request UUID.');
        }
        return $value;
    }

    private function request(string $method, string $path, ?array $input = null, ?string $key = null): array
    {
        $headers = ['Accept: application/json', 'Authorization: Bearer '.$this->token];
        if ($method !== 'GET') {
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) {
                throw new InvalidArgumentException('A stable Idempotency-Key is required.');
            }
            $headers[] = 'Idempotency-Key: '.$key;
            $headers[] = 'Content-Type: application/json';
        }
        $curl = curl_init($this->origin.'/api/v1'.$path);
        $response = '';
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 2097152) { return 0; }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($input !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);
        if ($ok === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Project Brief request failed (HTTP '.$status.'). Retry with the same external ID, payload and idempotency key.', $status);
        }
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) { throw new RuntimeException('Invalid Project Brief response.'); }
        return $decoded;
    }
}
