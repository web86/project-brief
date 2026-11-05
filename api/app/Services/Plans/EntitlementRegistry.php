<?php

namespace App\Services\Plans;

use InvalidArgumentException;

final class EntitlementRegistry
{
    public const REVISION = 1;

    public const MAX_INTEGER = 9007199254740991;

    public const MAX_SEATS = 50;

    public const BRANDING_SECTIONS = [
        'none' => [],
        'basic' => ['visual', 'assets'],
        'full' => ['visual', 'assets', 'business', 'contact', 'footer', 'export', 'email'],
    ];

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $result = [];
        foreach (['projects.active.max', 'clients.max', 'agents.active.max', 'developers.max', 'storage.bytes.max', 'storage.files.max'] as $key) {
            $result[$key] = ['kind' => 'integer', 'scopes' => ['workspace'], 'allow_unlimited' => true, 'default' => ['kind' => 'integer', 'value' => 0], 'paid_requirement' => $key === 'agents.active.max' ? 'paid_or_trial' : 'none', 'availability' => str_starts_with($key, 'storage.') ? 'planned' : 'available', 'derived' => $key === 'developers.max'];
            if (str_starts_with($key, 'storage.')) {
                $result[$key]['default'] = null;
            }
        }
        foreach (['agents.enabled', 'api.agents.enabled', 'api.external.enabled', 'webhooks.enabled', 'sso.enabled', 'formwatch.enabled'] as $key) {
            $result[$key] = ['kind' => 'boolean', 'scopes' => ['workspace'], 'allow_unlimited' => false, 'default' => ['kind' => 'boolean', 'value' => false], 'paid_requirement' => 'paid_or_trial', 'availability' => $key === 'formwatch.enabled' ? 'unavailable' : 'available', 'derived' => false];
        }
        $result['branding.level'] = ['kind' => 'enum', 'scopes' => ['workspace'], 'allow_unlimited' => false, 'values' => array_keys(self::BRANDING_SECTIONS), 'sections_by_level' => self::BRANDING_SECTIONS, 'default' => ['kind' => 'enum', 'value' => 'none'], 'paid_requirement' => 'paid_or_trial', 'availability' => 'available', 'derived' => false];
        foreach (['agents.runs.quota', 'ai.requests.quota', 'formwatch.checks.quota'] as $key) {
            $result[$key] = ['kind' => 'quota', 'scopes' => ['workspace'], 'allow_unlimited' => true, 'windows' => ['calendar_day_utc', 'calendar_month_utc'], 'unit' => match ($key) {
                'formwatch.checks.quota' => 'check', 'agents.runs.quota' => 'run', default => 'request'
            }, 'default' => null, 'paid_requirement' => $key === 'ai.requests.quota' ? 'none' : 'paid_or_trial', 'availability' => 'planned', 'derived' => false];
        }
        foreach ($result as $key => &$definition) {
            $definition['key'] = $key;
        }
        unset($definition);
        ksort($result);

        return $result;
    }

    public function definition(string $key): array
    {
        return $this->definitions()[$key] ?? throw new InvalidArgumentException('Unknown entitlement key.');
    }

    public function safeDefault(string $key): ?array
    {
        return $this->definition($key)['default'];
    }

    public function validate(string $key, mixed $value, string $scope = 'workspace', bool $allowDerived = false): array
    {
        $definition = $this->definition($key);
        if ((! $allowDerived && $definition['derived']) || ! in_array($scope, $definition['scopes'], true) || ! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Unsupported entitlement value or scope.');
        }
        $kind = $value['kind'] ?? null;
        $keys = array_keys($value);
        sort($keys);
        $valid = match ($kind) {
            'unlimited' => $definition['allow_unlimited'] && $keys === ['kind'],
            'boolean' => $definition['kind'] === $kind && $keys === ['kind', 'value'] && is_bool($value['value']),
            'integer' => $definition['kind'] === $kind && $keys === ['kind', 'value'] && $this->integer($value['value']),
            'enum' => $definition['kind'] === $kind && $keys === ['kind', 'value'] && in_array($value['value'], $definition['values'], true),
            'quota' => $definition['kind'] === $kind && $keys === ['kind', 'limit', 'unit', 'window'] && $this->integer($value['limit']) && $value['unit'] === $definition['unit'] && in_array($value['window'], $definition['windows'], true),
            default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException('Invalid typed entitlement value.');
        }

        return $value;
    }

    public function eligible(string $key, array $value, string $accessClass): bool
    {
        $definition = $this->definition($key);
        if ($definition['paid_requirement'] === 'none' || in_array($accessClass, ['paid', 'compatibility'], true)) {
            return true;
        }

        return match ($value['kind']) {
            'boolean' => $value['value'] === false,
            'integer' => $value['value'] === 0,
            'enum' => $value['value'] === 'none',
            'quota' => $value['limit'] === 0,
            default => false,
        };
    }

    public function seats(array $policy, int $approvedExtra = 0, bool $compatibility = false): array
    {
        $keys = array_keys($policy);
        sort($keys);
        if ($keys !== ['allow_extra', 'hard_max', 'included'] || ! is_bool($policy['allow_extra'])) {
            throw new InvalidArgumentException('Invalid developer seats policy.');
        }
        if ($compatibility && $policy['included'] === ['kind' => 'unlimited'] && $policy['hard_max'] === ['kind' => 'unlimited'] && ! $policy['allow_extra'] && $approvedExtra === 0) {
            return ['kind' => 'unlimited'];
        }
        if (! $this->integer($policy['included']) || ! $this->integer($policy['hard_max']) || $policy['included'] > $policy['hard_max'] || $policy['hard_max'] > self::MAX_SEATS || $approvedExtra < 0 || $approvedExtra > $policy['hard_max'] - $policy['included'] || (! $policy['allow_extra'] && $approvedExtra !== 0)) {
            throw new InvalidArgumentException('Developer seats exceed the approved policy.');
        }

        return ['kind' => 'integer', 'value' => $policy['included'] + $approvedExtra];
    }

    private function integer(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= self::MAX_INTEGER;
    }
}
