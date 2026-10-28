<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

class WorkspaceBranding
{
    public const FIELDS = [
        'visual' => ['companyName' => 160, 'primaryColor' => 7],
        'business' => ['legalName' => 255, 'taxId' => 80, 'bankName' => 160, 'payee' => 160, 'account' => 100, 'bic' => 40, 'note' => 2000],
        'contact' => ['country' => 100, 'city' => 100, 'address' => 255, 'postalCode' => 30, 'phone' => 80, 'email' => 254, 'website' => 2048, 'extra' => 1000],
    ];

    public const EXPORT_DEFAULTS = ['logo' => true, 'companyName' => true, 'address' => false, 'contact' => false, 'business' => false, 'platform' => true];

    public const EMAIL_DEFAULTS = ['enabled' => false, 'logo' => true, 'companyName' => true, 'contact' => false, 'accent' => true];

    public function settings(Workspace $workspace): array
    {
        $stored = $workspace->branding ?? [];

        return ['visual' => array_replace(array_fill_keys(array_keys(self::FIELDS['visual']), ''), $stored['visual'] ?? []),
            'business' => array_replace(array_fill_keys(array_keys(self::FIELDS['business']), ''), $stored['business'] ?? []),
            'contact' => array_replace(array_fill_keys(array_keys(self::FIELDS['contact']), ''), $stored['contact'] ?? []),
            'export' => array_replace(self::EXPORT_DEFAULTS, $stored['export'] ?? []),
            'email' => array_replace(self::EMAIL_DEFAULTS, $stored['email'] ?? []),
            'assets' => $stored['assets'] ?? []];
    }

    public function assetUrl(Workspace $workspace, string $kind, bool $browser = true): ?string
    {
        $asset = $workspace->branding['assets'][$kind] ?? null;

        $extension = $browser && ! empty($asset['svgPath']) ? 'svg' : ($asset['extension'] ?? 'png');

        return $asset ? rtrim(config('app.url'), '/').'/branding-assets/'.$workspace->uuid.'/'.$asset['token'].'.'.$extension : null;
    }

    public function ownerState(Workspace $workspace): array
    {
        $settings = $this->settings($workspace);
        $settings['assets'] = ['logo' => $this->assetUrl($workspace, 'logo'), 'favicon' => $this->assetUrl($workspace, 'favicon')];

        $settings['versions'] = $this->versions($workspace);
        $settings['assetInfo'] = array_map(fn (array $asset): array => ['originalBytes' => $asset['originalBytes'] ?? null, 'bytes' => $asset['browserBytes'] ?? $asset['bytes'] ?? null, 'mime' => ! empty($asset['svgPath']) ? 'image/svg+xml' : ($asset['mime'] ?? 'image/png'), 'fallbackBytes' => $asset['bytes'] ?? null], $workspace->branding['assets'] ?? []);

        return $settings;
    }

    public function versions(Workspace $workspace): array
    {
        return array_map(fn (array $values): string => hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), array_intersect_key($this->settings($workspace), array_flip(['visual', 'business', 'contact', 'export', 'email'])));
    }

    public function portal(Workspace $workspace): array
    {
        $visual = $this->settings($workspace)['visual'];

        return ['companyName' => $visual['companyName'], 'primaryColor' => $visual['primaryColor'], 'logoUrl' => $this->assetUrl($workspace, 'logo'), 'faviconUrl' => $this->assetUrl($workspace, 'favicon'), 'faviconType' => ! empty($workspace->branding['assets']['favicon']['svgPath']) ? 'image/svg+xml' : ($workspace->branding['assets']['favicon']['mime'] ?? 'image/png')];
    }

    public function contact(array $values, bool $address): array
    {
        $keys = $address ? ['country', 'city', 'address', 'postalCode'] : ['phone', 'email', 'website', 'extra'];

        return array_values(array_filter(array_intersect_key($values, array_flip($keys)), fn ($v) => trim($v) !== ''));
    }

    public function email(Workspace $workspace): ?array
    {
        $settings = $this->settings($workspace);
        $flags = $settings['email'];
        if (! $flags['enabled']) {
            return null;
        }
        $color = $flags['accent'] && preg_match('/^#[a-f0-9]{6}$/iD', $settings['visual']['primaryColor']) ? $settings['visual']['primaryColor'] : '#246c43';
        $luminance = function (string $hex): float {
            $rgb = array_map(fn ($v) => hexdec($v) / 255, str_split(substr($hex, 1), 2));
            $rgb = array_map(fn ($v) => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $rgb);

            return $rgb[0] * 0.2126 + $rgb[1] * 0.7152 + $rgb[2] * 0.0722;
        };
        $textColor = $color;
        for ($i = 0; $i < 50 && (1.05 / ($luminance($textColor) + 0.05)) < 4.5; $i++) {
            $textColor = '#'.implode('', array_map(fn ($v) => str_pad(dechex((int) round(hexdec($v) * 0.9)), 2, '0', STR_PAD_LEFT), str_split(substr($textColor, 1), 2)));
        }

        return ['name' => $flags['companyName'] ? $settings['visual']['companyName'] : '',
            'logoUrl' => $flags['logo'] ? $this->assetUrl($workspace, 'logo', false) : null,
            'color' => $color, 'textColor' => $textColor, 'onColor' => $luminance($color) > 0.179 ? '#000000' : '#ffffff',
            'contact' => $flags['contact'] ? $this->contact($settings['contact'], false) : []];
    }

    public function document(Workspace $workspace, string $locale): array
    {
        $settings = $this->settings($workspace);
        $flags = $settings['export'];
        $asset = $workspace->branding['assets']['logo'] ?? null;
        $logo = $flags['logo'] && $asset && Storage::disk('local')->exists($asset['path']) ? 'data:'.($asset['mime'] ?? 'image/png').';base64,'.base64_encode(Storage::disk('local')->get($asset['path'])) : null;
        $rows = [];
        if ($flags['business']) {
            foreach ($settings['business'] as $key => $value) {
                if (trim($value) !== '') {
                    $rows[] = ['label' => trans('branding.fields.'.$key, [], $locale), 'value' => $value];
                }
            }
        }

        return ['name' => $flags['companyName'] ? $settings['visual']['companyName'] : '', 'logo' => $logo,
            'address' => $flags['address'] ? $this->contact($settings['contact'], true) : [],
            'contact' => $flags['contact'] ? $this->contact($settings['contact'], false) : [], 'business' => $rows,
            'businessTitle' => trans('branding.business', [], $locale), 'platform' => $flags['platform'], 'platformUrl' => rtrim(config('app.url'), '/')];
    }
}
