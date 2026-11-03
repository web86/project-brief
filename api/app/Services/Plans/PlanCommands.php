<?php

namespace App\Services\Plans;

use App\Models\User;
use App\Services\IntegrationWrites;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PlanCommands
{
    public function __construct(private readonly PlanAuthority $authority) {}

    /** @param callable(object): array{type: string, id: int, uuid: string, before: int, after: int} $operation */
    public function run(User $actor, string $action, string $key, array $input, callable $operation, ?int $workspaceId = null): array
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key) || ! in_array($action, ['management.preview', 'management.publish', 'plan.metadata', 'plan.unarchive', 'rules.copy_preview', 'rules.copy', 'plan.create', 'plan.revise', 'plan.publish', 'plan.next_draft', 'plan.archive', 'plan.transition_rule', 'policy.save', 'policy.publish', 'subscription.pin', 'subscription.override', 'subscription.approve_paid', 'canary.registration.issue', 'canary.approve', 'canary.stop', 'canary.admit'], true)) {
            throw new InvalidArgumentException('Invalid operation key.');
        }
        $keyHash = hash('sha256', $actor->id.'|'.$key);
        $requestHash = PlanConfiguration::hash(['action' => $action, 'workspace_id' => $workspaceId, 'input' => $input]);

        return DB::transaction(function () use ($actor, $action, $keyHash, $requestHash, $operation, $workspaceId): array {
            if ($workspaceId !== null) {
                IntegrationWrites::lockWorkspace($workspaceId);
            }
            $authority = $this->authority->requirePrimary($actor);
            $previous = DB::table('plan_events')->where('operation_key_hash', $keyHash)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, $requestHash), 409, 'Operation key body conflict.');

                return json_decode($previous->result_json, true, 16, JSON_THROW_ON_ERROR);
            }
            $outcome = $operation($authority);
            DB::table('plan_events')->insert(['uuid' => (string) Str::uuid(), 'aggregate_type' => $outcome['type'], 'aggregate_uuid' => $outcome['uuid'], 'workspace_id' => $workspaceId, 'actor_kind' => 'primary', 'actor_user_id' => $actor->id, 'action' => $action, 'result' => 'applied', 'before_revision' => $outcome['before'], 'after_revision' => $outcome['after'], 'diff_json' => json_encode(['authority_revision' => $authority->revision, 'record_id' => $outcome['id']], JSON_THROW_ON_ERROR), 'operation_key_hash' => $keyHash, 'request_hash' => $requestHash, 'result_json' => json_encode($outcome, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);

            return $outcome;
        }, 3);
    }
}
