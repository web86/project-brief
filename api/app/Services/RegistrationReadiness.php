<?php

namespace App\Services;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\PlanConfiguration;
use App\Services\Plans\PlatformPolicyCatalog;
use Illuminate\Support\Facades\DB;

final class RegistrationReadiness
{
    public const PROVIDERS = ['google', 'yandex', 'github'];

    public function registrationEnabled(): bool
    {
        return app()->environment(['local', 'staging', 'testing']) && config('onboarding.registration_enabled') === true && ! config('demo.enabled', false);
    }

    public function activationEnabled(): bool
    {
        return false;
    }

    public function callback(string $provider): ?string
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            return null;
        }
        $value = config('onboarding.providers.'.$provider.'.callback');
        $base = parse_url((string) config('app.url'));
        $url = is_string($value) ? parse_url($value) : false;
        if (! $url || ! $base || ! filter_var($value, FILTER_VALIDATE_URL) || (isset($url['user']) || isset($url['pass'])) || isset($url['query']) || isset($url['fragment']) || ($url['host'] ?? null) !== ($base['host'] ?? null) || ($url['port'] ?? null) !== ($base['port'] ?? null) || ($url['scheme'] ?? '') !== ($base['scheme'] ?? '') || ($url['path'] ?? '') !== '/auth/'.$provider.'/callback') {
            return null;
        }
        if (($url['scheme'] ?? '') !== 'https' && ! (app()->environment(['local', 'testing']) && in_array($url['host'], ['localhost', '127.0.0.1'], true))) {
            return null;
        }

        return $value;
    }

    public function provider(string $provider): bool
    {
        return $this->callback($provider) !== null && is_string(config('onboarding.providers.'.$provider.'.client_id')) && config('onboarding.providers.'.$provider.'.client_id') !== '' && is_string(config('onboarding.providers.'.$provider.'.client_secret')) && config('onboarding.providers.'.$provider.'.client_secret') !== '' && ! config('demo.enabled', false);
    }

    public function targets(): ?array
    {
        $id = DB::table('plan_settings')->where('id', 1)->value('current_policy_version_id');
        $policy = $id ? PlatformPolicyVersion::find($id) : null;
        if (! $policy || $policy->state !== 'published' || $policy->trial_days !== 7 || $policy->grace_days !== 7) {
            return null;
        }
        $studio = PlanVersion::with('plan')->find($policy->trial_version_id);
        $basic = PlanVersion::with('plan')->find($policy->fallback_version_id);
        if (! $studio || ! $basic || $studio->state !== 'published' || $basic->state !== 'published' || $studio->access_class !== 'paid' || $basic->access_class !== 'free' || $studio->plan->status === 'archived' || $basic->plan->status === 'archived') {
            return null;
        }
        if (! hash_equals($policy->content_hash, PlanConfiguration::hash(app(PlatformPolicyCatalog::class)->configuration($policy)))) {
            return null;
        }
        foreach ([$studio, $basic] as $v) {
            $configuration = app(PlanCatalog::class)->configuration($v);
            app(PlanConfiguration::class)->validate($configuration);
            if (! hash_equals($v->content_hash, PlanConfiguration::versionHash($configuration))) {
                return null;
            }
        }

        return ['policy' => $policy, 'studio' => $studio, 'basic' => $basic];
    }

    /** Primary-only diagnostics. Configuration checks are separate from verified operational evidence. */
    public function commercialBlockers(bool $targetsReady): array
    {
        $reasons = [
            'phase_4b2_lifecycle_required',
            'execution_rearm_review_required',
            'rollback_verification_required',
            'notification_delivery_verification_required',
        ];
        if (! $targetsReady) {
            $reasons[] = 'published_targets_required';
        }

        $settings = DB::table('plan_settings')->where('id', 1)->first();
        $cohort = DB::table('plan_inventory_cohorts')->where('id', 1)->first();
        $legacy = $settings?->legacy_version_id ? PlanVersion::find($settings->legacy_version_id) : null;
        if (! $cohort || $legacy?->state !== 'published' || $legacy->access_class !== 'compatibility') {
            $reasons[] = 'legacy_cohort_required';
        } elseif (DB::table('workspaces')->where('workspaces.id', '<=', $cohort->workspace_cutoff_id)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('plan_inventory_items')
                ->whereColumn('plan_inventory_items.workspace_id', 'workspaces.id')->where('state', 'processed'))->exists()) {
            $reasons[] = 'legacy_accounting_review_required';
        }

        if (! app(ProjectNotificationService::class)->mailAvailable()) {
            $reasons[] = 'smtp_configuration_required';
        }

        return $reasons;
    }

    public function publicState(): array
    {
        return ['registrationEnabled' => $this->registrationEnabled(), 'trialActivationEnabled' => false, 'providers' => array_combine(self::PROVIDERS, array_map(fn ($p) => $this->provider($p), self::PROVIDERS))];
    }
}
