<?php

namespace App\Services;

class SupportedLocales
{
    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(config('localization.languages'));
    }

    public static function resolve(?string $locale): string
    {
        return in_array($locale, self::codes(), true) ? $locale : config('localization.fallback');
    }

    public static function settings(string $locale): array
    {
        return config('localization.languages.'.self::resolve($locale));
    }
}
