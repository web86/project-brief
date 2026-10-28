<?php

namespace App\Services;

class CompanyWebsite
{
    public static function normalize(?string $value): string
    {
        $value = trim($value ?? '');
        if ($value !== '' && ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) && ! str_starts_with($value, '//')) {
            $value = 'https://'.$value;
        }

        return $value;
    }

    public static function valid(?string $value): bool
    {
        $value = self::normalize($value);
        if ($value === '') {
            return true;
        }
        if (preg_match('/[\s\\\\]/u', $value)) {
            return false;
        }
        $url = parse_url($value);
        if (! $url || ! in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true) || isset($url['user']) || isset($url['pass'])) {
            return false;
        }
        $host = $url['host'] ?? '';

        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || preg_match('/^(?:[\pL\pN](?:[\pL\pN-]*[\pL\pN])?\.)+(?:[\pL]{2,}|xn--[a-zA-Z0-9-]{2,})\.?$/uD', $host) === 1;
    }
}
