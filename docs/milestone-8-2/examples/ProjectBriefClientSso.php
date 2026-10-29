<?php
/** Small server-only cURL client. PHP 7.4+ syntax; production QA targets PHP 8.4. */
final class ProjectBriefClientSso
{
    private string $origin;
    private string $token;
    private ?string $caFile;

    public function __construct(string $origin, string $token, ?string $caFile = null)
    {
        $parts = parse_url($origin);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true) || !preg_match('/^pb_[a-f0-9]{64}$/D', $token)) {
            throw new InvalidArgumentException('Invalid trusted handoff configuration.');
        }
        $this->origin = rtrim($origin, '/');
        $this->token = $token;
        $this->caFile = $caFile;
    }

    public function issue(string $projectId, string $externalUserId, string $verifiedEmail, bool $emailVerified): array
    {
        if (!$emailVerified) { throw new RuntimeException('Verified client email is required.'); }
        $curl = curl_init($this->origin.'/api/v1/client-sso/handoffs');
        $body = '';
        $options = [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer '.$this->token],
            CURLOPT_POSTFIELDS => json_encode(['projectId' => $projectId, 'externalUserId' => $externalUserId, 'email' => $verifiedEmail, 'emailVerified' => true], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 8192) { return 0; }
                $body .= $chunk; return strlen($chunk);
            },
        ];
        if ($this->caFile !== null) { $options[CURLOPT_CAINFO] = $this->caFile; }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); unset($curl);
        if ($ok === false || $status !== 201) { throw new RuntimeException('Trusted sign-in is unavailable. Use the normal email sign-in.'); }
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR)['data'] ?? null;
        if (!is_array($data) || !preg_match('/^[a-f0-9]{64}$/D', $data['code'] ?? '') || ($data['postUrl'] ?? '') !== $this->origin.'/client-sso/enter') { throw new RuntimeException('Invalid trusted handoff response.'); }
        return $data;
    }

    public function transferPage(array $handoff, string $continueLabel = 'Continue'): void
    {
        if (($handoff['postUrl'] ?? '') !== $this->origin.'/client-sso/enter' || !preg_match('/^[a-f0-9]{64}$/D', $handoff['code'] ?? '')) { throw new RuntimeException('Invalid handoff.'); }
        $nonce = base64_encode(random_bytes(16));
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0'); header('Pragma: no-cache'); header('Expires: 0');
        header('Referrer-Policy: origin'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; script-src 'nonce-".$nonce."'; form-action ".$this->origin."/client-sso/enter; base-uri 'none'; frame-ancestors 'none'");
        $escape = static function (string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="referrer" content="origin"><title>'.$escape($continueLabel).'</title></head><body>';
        echo '<form id="handoff" method="post" action="'.$escape($handoff['postUrl']).'"><input type="hidden" name="code" value="'.$escape($handoff['code']).'"><noscript><button type="submit">'.$escape($continueLabel).'</button></noscript></form>';
        echo '<script nonce="'.$escape($nonce).'">document.getElementById("handoff").submit();document.querySelector("input[name=code]").value="";</script></body></html>';
    }
}
