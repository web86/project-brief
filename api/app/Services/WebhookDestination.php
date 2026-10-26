<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class WebhookDestination
{
    /** Resolve again for every attempt. Every answer must be public; the selected address is pinned in cURL. */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443 || strlen($url) > 2048 || ! preg_match('/^[a-zA-Z0-9.-]+$/D', $host) || ! str_contains($host, '.')) {
            $this->invalid();
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->addresses($host);
        if (! $addresses) {
            $this->invalid();
        }
        foreach ($addresses as $ip) {
            if (! $this->publicIp($ip)) {
                $this->invalid();
            }
        }

        return ['host' => $host, 'ip' => $addresses[0]];
    }

    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_unique(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records ?: []))));
    }

    public function publicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            $binary = inet_pton($ip);

            return $binary !== false && (ord($binary[0]) & 0xE0) === 0x20 && ! $this->inRange($ip, '2001::', 23) && ! $this->inRange($ip, '2001:db8::', 32) && ! $this->inRange($ip, '2002::', 16) && ! $this->inRange($ip, '3fff::', 20);
        }
        foreach ([['0.0.0.0', 8], ['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$net, $bits]) {
            if ($this->inRange($ip, $net, $bits)) {
                return false;
            }
        }

        return true;
    }

    private function inRange(string $ip, string $network, int $bits): bool
    {
        $value = inet_pton($ip);
        $base = inet_pton($network);
        $bytes = intdiv($bits, 8);
        $remaining = $bits % 8;

        return substr($value, 0, $bytes) === substr($base, 0, $bytes) && (! $remaining || (ord($value[$bytes]) >> (8 - $remaining)) === (ord($base[$bytes]) >> (8 - $remaining)));
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['url' => 'Use an HTTPS URL on port 443 resolving only to public IP addresses.']);
    }
}
