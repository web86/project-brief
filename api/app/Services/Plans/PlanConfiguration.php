<?php

namespace App\Services\Plans;

use InvalidArgumentException;

final class PlanConfiguration
{
    public function __construct(private readonly EntitlementRegistry $registry) {}

    public function validate(array $config): array
    {
        $keys = array_keys($config);
        sort($keys);
        if ($keys !== ['access_class', 'developer_seats', 'entitlements', 'price', 'translations', 'visibility'] || ! in_array($config['access_class'], ['free', 'paid', 'compatibility'], true) || ! in_array($config['visibility'], ['public', 'hidden'], true) || ($config['access_class'] === 'compatibility' && $config['visibility'] !== 'hidden')) {
            throw new InvalidArgumentException('Invalid plan configuration.');
        }
        $this->bounded($config);
        if (! is_array($config['translations']) || ! is_array($config['price']) || ! is_array($config['developer_seats']) || ! is_array($config['entitlements'])) {
            throw new InvalidArgumentException('Typed configuration objects are required.');
        }
        $locales = array_keys($config['translations']);
        sort($locales);
        if ($locales !== ['en', 'ru', 'tr', 'zh-CN']) {
            throw new InvalidArgumentException('All four plan translations are required.');
        }
        foreach ($config['translations'] as $translation) {
            if (! is_array($translation) || array_diff(array_keys($translation), ['name', 'description']) !== [] || ! isset($translation['name']) || ! $this->text($translation['name'], 160, true) || ! $this->text($translation['description'] ?? '', 4000)) {
                throw new InvalidArgumentException('Invalid plain text translation.');
            }
        }
        $price = $config['price'];
        $priceKeys = array_keys($price);
        sort($priceKeys);
        if ($priceKeys !== ['amount_minor', 'currency', 'interval', 'mode', 'scale'] || ! in_array($price['mode'], ['free', 'paid'], true) || ($config['access_class'] === 'free' && $price['mode'] !== 'free') || ($config['access_class'] === 'paid' && $price['mode'] !== 'paid') || ($price['amount_minor'] !== null && (! is_int($price['amount_minor']) || $price['amount_minor'] < 0 || $price['amount_minor'] > EntitlementRegistry::MAX_INTEGER)) || ($price['currency'] !== null && (! is_string($price['currency']) || ! preg_match('/^[A-Z]{3}$/D', $price['currency']))) || ! is_int($price['scale']) || $price['scale'] < 0 || $price['scale'] > 4 || ! in_array($price['interval'], [null, 'month', 'year', 'custom_days'], true) || ($price['amount_minor'] !== null && $price['currency'] === null) || ($price['mode'] === 'free' && ! in_array($price['amount_minor'], [0, null], true))) {
            throw new InvalidArgumentException('Invalid price metadata.');
        }
        $this->registry->seats($config['developer_seats'], 0, $config['access_class'] === 'compatibility');
        foreach ($config['entitlements'] as $key => $value) {
            $this->registry->validate($key, $value);
            if (! $this->registry->eligible($key, $value, $config['access_class'])) {
                throw new InvalidArgumentException('Paid entitlement on a free plan.');
            }
            if ($this->registry->definition($key)['availability'] !== 'available') {
                throw new InvalidArgumentException('Unconfigured capability is unavailable.');
            }
        }
        foreach ($this->registry->definitions() as $key => $definition) {
            if ($definition['availability'] === 'available' && ! $definition['derived'] && ! array_key_exists($key, $config['entitlements'])) {
                throw new InvalidArgumentException('Missing required entitlement.');
            }
        }

        return $config;
    }

    public static function versionHash(array $configuration): string
    {
        unset($configuration['visibility']);

        return self::hash($configuration);
    }

    public static function hash(array $data): string
    {
        return hash('sha256', json_encode(self::canonical($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function bounded(array $data): void
    {
        try {
            $length = strlen(json_encode($data, JSON_THROW_ON_ERROR, 16));
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Invalid bounded JSON configuration.', previous: $e);
        }
        if ($length > 65536) {
            throw new InvalidArgumentException('Configuration exceeds the allowed size.');
        }
    }

    private static function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            }
        }

        return $data;
    }

    private function text(mixed $value, int $max, bool $required = false): bool
    {
        return is_string($value) && mb_strlen($value) <= $max && (! $required || trim($value) !== '') && strip_tags($value) === $value && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value);
    }
}
