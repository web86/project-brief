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
        foreach (config('localization.languages') as $code => $settings) {
            if ($locale === $code || $locale === ($settings['storage'] ?? $code)) {
                return $code;
            }
        }

        return config('localization.fallback');
    }

    public static function storage(string $locale): string
    {
        $code = self::resolve($locale);

        return config('localization.languages.'.$code.'.storage', $code);
    }

    public static function preference(?string $locale): ?string
    {
        return $locale === null ? null : self::resolve($locale);
    }

    public static function browser(?string $locale): string
    {
        $tag = strtolower(str_replace('_', '-', trim($locale ?? '')));
        $parts = explode('-', $tag);
        if ($parts[0] === 'zh' && in_array('hant', $parts, true)) {
            return config('localization.fallback');
        }
        foreach (config('localization.languages') as $code => $settings) {
            foreach ([$code, ...($settings['browser_aliases'] ?? [])] as $alias) {
                $candidate = strtolower($alias);
                if ($tag === $candidate || str_starts_with($tag, $candidate.'-')) {
                    return $code;
                }
            }
        }

        return config('localization.fallback');
    }

    public static function settings(string $locale): array
    {
        return config('localization.languages.'.self::resolve($locale));
    }
}
