<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneOnboardingAttempts extends Command
{
    protected $signature = 'onboarding:prune-attempts {--limit=100} {--dry-run}';

    protected $description = 'Remove a bounded batch of expired OAuth attempts, never durable Trial subjects';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('Invalid limit.');

            return self::FAILURE;
        }
        $ids = DB::table('social_auth_attempts')->whereIn('state', ['pending', 'processing', 'email_required', 'completed', 'failed'])->where('expires_at', '<=', now('UTC'))->orderBy('expires_at')->limit($limit)->pluck('state_hash');
        $deleted = $this->option('dry-run') ? 0 : DB::table('social_auth_attempts')->whereIn('state_hash', $ids)->where('expires_at', '<=', now('UTC'))->delete();
        $this->line(json_encode(['expiredBatch' => $ids->count(), 'removed' => $deleted, 'dryRun' => (bool) $this->option('dry-run')], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
