<?php

namespace App\Services;

class PushEndpoint
{
    /** Browser push services only: untrusted subscription URLs must never reach arbitrary servers. */
    public static function valid(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (! $parts || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');

        return in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'web.push.apple.com'], true)
         || str_ends_with($host, '.push.services.mozilla.com') || str_ends_with($host, '.notify.windows.com');
    }
}
