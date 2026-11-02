<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->create('platform_plan_authority', function (Blueprint $t): void {
            $t->unsignedBigInteger('id')->primary();
            $this->ref($t, 'primary_user_id', 'users', true);
            $t->unsignedBigInteger('revision')->default(1);
            $t->dateTime('assigned_at', 6)->nullable();
            $t->unsignedBigInteger('operator_event_id')->nullable();
        });
        $this->create('entitlement_definitions', function (Blueprint $t): void {
            $t->string('key', 80)->primary();
            $t->enum('kind', ['boolean', 'integer', 'enum', 'quota']);
            $t->json('allowed_scopes_json');
            $t->json('schema_json');
            $t->enum('paid_requirement', ['none', 'paid_or_trial']);
            $t->enum('availability', ['available', 'planned', 'unavailable']);
            $t->boolean('derived')->default(false);
            $t->unsignedBigInteger('schema_revision');
        });
        $this->create('plans', function (Blueprint $t): void {
            $this->identity($t);
            $t->string('code', 64)->unique('pf_plan_code_uq');
            $t->enum('status', ['draft', 'published', 'archived']);
            $t->enum('visibility', ['public', 'hidden']);
            $t->unsignedBigInteger('published_version_id')->nullable();
            $t->integer('sort_order')->default(0);
            $t->unsignedBigInteger('revision')->default(1);
            $this->actors($t);
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
        });
        $this->create('plan_versions', function (Blueprint $t): void {
            $this->identity($t);
            $this->ref($t, 'plan_id', 'plans');
            $t->unsignedInteger('number');
            $t->enum('state', ['draft', 'published']);
            $t->unsignedBigInteger('draft_plan_id')->nullable()->storedAs("CASE WHEN state = 'draft' THEN plan_id ELSE NULL END");
            $t->unique('draft_plan_id', 'pf_one_draft_uq');
            $t->enum('access_class', ['free', 'paid', 'compatibility']);
            foreach (['translations_json', 'price_json', 'developer_seats_json'] as $field) {
                $t->json($field);
            }
            $t->unsignedBigInteger('registry_revision');
            $t->char('content_hash', 64)->nullable();
            $t->unsignedBigInteger('revision')->default(1);
            $this->actors($t);
            $t->dateTime('published_at', 6)->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['plan_id', 'number'], 'pf_version_number_uq');
            $t->unique(['id', 'plan_id'], 'pf_version_plan_uq');
        });
        $this->foreign('plans', ['published_version_id', 'id'], 'plan_versions', ['id', 'plan_id'], 'pf_plan_current_fk');
        $this->create('plan_entitlement_values', function (Blueprint $t): void {
            $this->ref($t, 'plan_version_id', 'plan_versions');
            $t->string('entitlement_key', 80);
            $t->foreign('entitlement_key', 'pf_value_key_fk')->references('key')->on('entitlement_definitions')->restrictOnDelete();
            $t->enum('scope_kind', ['workspace', 'project']);
            $t->json('value_json');
            $t->primary(['plan_version_id', 'entitlement_key'], 'pf_value_pk');
        });
        $this->create('platform_policy_versions', function (Blueprint $t): void {
            $this->identity($t);
            $t->unsignedInteger('number')->unique('pf_policy_number_uq');
            $t->enum('state', ['draft', 'published']);
            $t->unsignedInteger('trial_days');
            $t->unsignedInteger('grace_days');
            $this->ref($t, 'trial_version_id', 'plan_versions');
            $this->ref($t, 'fallback_version_id', 'plan_versions');
            foreach (['eligibility_json', 'security_termination_json', 'retention_json'] as $field) {
                $t->json($field);
            }
            $t->char('content_hash', 64)->nullable();
            $t->unsignedBigInteger('revision')->default(1);
            $this->actors($t);
            $t->dateTime('published_at', 6)->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
        });
        $this->create('plan_settings', function (Blueprint $t): void {
            $t->unsignedBigInteger('id')->primary();
            $this->ref($t, 'current_policy_version_id', 'platform_policy_versions', true);
            $this->ref($t, 'legacy_version_id', 'plan_versions', true);
            $t->enum('rollout_mode', ['off', 'observe', 'enforce'])->default('off');
            $t->unsignedBigInteger('registry_revision')->default(1);
            $t->unsignedBigInteger('revision')->default(1);
        });
        $this->create('plan_transition_rules', function (Blueprint $t): void {
            $this->identity($t);
            $this->ref($t, 'source_version_id', 'plan_versions');
            $this->ref($t, 'target_version_id', 'plan_versions');
            $t->enum('initiator', ['platform', 'owner']);
            $t->enum('approval', ['none', 'manual', 'payment']);
            $t->enum('timing', ['immediate', 'period_end']);
            $t->json('overage_policy');
            $t->boolean('enabled')->default(false);
            $t->unsignedBigInteger('revision')->default(1);
            $this->actors($t);
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['source_version_id', 'target_version_id', 'initiator'], 'pf_rule_endpoints_uq');
        });
        $this->create('workspace_subscriptions', function (Blueprint $t): void {
            $this->identity($t);
            $this->ref($t, 'workspace_id', 'workspaces');
            $t->unique('workspace_id', 'pf_subscription_workspace_uq');
            $t->unique(['id', 'workspace_id'], 'pf_subscription_tenant_uq');
            $t->unsignedBigInteger('current_period_id')->nullable();
            $t->unsignedInteger('approved_extra_seats')->default(0);
            $t->enum('enforcement_mode', ['observe', 'enforce'])->default('observe');
            $t->unsignedBigInteger('revision')->default(1);
            $t->dateTime('next_boundary_at', 6)->nullable();
            $t->string('materialized_phase', 32)->default('unavailable');
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
        });
        $this->create('subscription_periods', function (Blueprint $t): void {
            $this->identity($t);
            $this->workspaceSubscription($t);
            $t->enum('kind', ['compatibility', 'free', 'trial', 'paid']);
            $this->ref($t, 'entitlement_version_id', 'plan_versions');
            $this->ref($t, 'policy_version_id', 'platform_policy_versions', true);
            $this->ref($t, 'fallback_version_id', 'plan_versions', true);
            $t->dateTime('starts_at', 6);
            foreach (['service_ends_at', 'grace_ends_at', 'superseded_at'] as $field) {
                $t->dateTime($field, 6)->nullable();
            }
            $t->enum('approval_kind', ['manual', 'trial_verification', 'migration', 'system']);
            $this->ref($t, 'approved_by', 'users', true);
            $t->unsignedBigInteger('approval_change_id')->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['id', 'workspace_id'], 'pf_period_tenant_uq');
            $t->unique(['id', 'subscription_id', 'workspace_id'], 'pf_period_subscription_uq');
            $t->index(['service_ends_at', 'id'], 'pf_period_service_idx');
            $t->index(['grace_ends_at', 'id'], 'pf_period_grace_idx');
        });
        $this->foreign('workspace_subscriptions', ['current_period_id', 'id', 'workspace_id'], 'subscription_periods', ['id', 'subscription_id', 'workspace_id'], 'pf_subscription_current_fk');
        $this->create('owner_trial_claims', function (Blueprint $t): void {
            $this->identity($t);
            $this->ref($t, 'owner_user_id', 'users', true);
            $t->unique('owner_user_id', 'pf_claim_owner_uq');
            $this->ref($t, 'workspace_id', 'workspaces');
            $t->unsignedBigInteger('period_id')->nullable();
            $t->foreign(['period_id', 'workspace_id'], 'pf_claim_period_fk')->references(['id', 'workspace_id'])->on('subscription_periods')->restrictOnDelete();
            $t->dateTime('registered_at', 6);
            foreach (['verified_at', 'starts_at', 'ends_at'] as $field) {
                $t->dateTime($field, 6)->nullable();
            }
            $t->enum('state', ['pending', 'active', 'used', 'expired', 'denied']);
            $t->string('reason_code', 60)->nullable();
            $t->index(['state', 'ends_at', 'id'], 'pf_claim_expiry_idx');
        });
        $this->create('workspace_entitlement_overrides', function (Blueprint $t): void {
            $this->identity($t);
            $this->workspaceSubscription($t);
            $t->string('entitlement_key', 80);
            $t->foreign('entitlement_key', 'pf_override_key_fk')->references('key')->on('entitlement_definitions')->restrictOnDelete();
            $t->enum('scope_kind', ['workspace', 'project']);
            $t->json('value_json');
            $t->dateTime('starts_at', 6);
            $t->dateTime('ends_at', 6)->nullable();
            $t->string('reason_code', 60);
            $this->ref($t, 'approved_by', 'users', true);
            $t->unsignedBigInteger('revision')->default(1);
            $t->index(['workspace_id', 'entitlement_key', 'starts_at', 'ends_at'], 'pf_override_active_idx');
        });
        $this->create('subscription_changes', function (Blueprint $t): void {
            $this->identity($t);
            $this->workspaceSubscription($t);
            $t->enum('kind', ['assign', 'transition', 'renew', 'seats', 'enforcement', 'restore', 'terminate']);
            $this->ref($t, 'target_version_id', 'plan_versions', true);
            $t->unsignedBigInteger('expected_revision');
            $t->json('payload_json');
            $this->ref($t, 'rule_id', 'plan_transition_rules', true);
            $t->unsignedBigInteger('rule_revision')->nullable();
            $t->json('policy_snapshot_json');
            $t->unsignedBigInteger('preview_id')->nullable();
            $t->uuid('batch_uuid')->nullable();
            $this->ref($t, 'requested_by', 'users', true);
            $this->ref($t, 'approved_by', 'users', true);
            $t->unsignedBigInteger('authority_revision')->nullable();
            $t->enum('state', ['requested', 'approved', 'scheduled', 'applied', 'rejected', 'cancelled', 'failed']);
            $t->dateTime('effective_at', 6)->nullable();
            $t->char('operation_key_hash', 64);
            $t->char('request_hash', 64);
            $t->dateTime('applied_at', 6)->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['workspace_id', 'operation_key_hash'], 'pf_change_operation_uq');
            $t->index(['state', 'effective_at', 'id'], 'pf_change_due_idx');
            $t->index(['batch_uuid', 'id'], 'pf_change_batch_idx');
        });
        $this->foreign('subscription_periods', ['approval_change_id', 'workspace_id'], 'subscription_changes', ['id', 'workspace_id'], 'pf_period_change_fk', true);
        $this->create('plan_events', function (Blueprint $t): void {
            $this->identity($t);
            $t->string('aggregate_type', 40);
            $t->uuid('aggregate_uuid');
            $this->ref($t, 'workspace_id', 'workspaces', true);
            $t->enum('actor_kind', ['primary', 'owner', 'system', 'operator']);
            $this->ref($t, 'actor_user_id', 'users', true);
            $t->string('action', 60);
            $t->string('result', 32);
            $t->unsignedBigInteger('before_revision')->nullable();
            $t->unsignedBigInteger('after_revision')->nullable();
            $t->string('reason_code', 60)->nullable();
            $t->json('diff_json');
            $t->unsignedBigInteger('change_id')->nullable();
            $t->foreign(['change_id', 'workspace_id'], 'pf_event_change_fk')->references(['id', 'workspace_id'])->on('subscription_changes')->restrictOnDelete();
            $t->char('operation_key_hash', 64)->nullable()->unique('pf_event_operation_uq');
            $t->char('request_hash', 64)->nullable();
            $t->json('result_json')->nullable();
            $t->dateTime('created_at', 6);
            $t->index(['aggregate_type', 'aggregate_uuid', 'id'], 'pf_event_aggregate_idx');
            $t->index(['workspace_id', 'created_at', 'id'], 'pf_event_workspace_idx');
        });
        $this->foreign('platform_plan_authority', ['operator_event_id'], 'plan_events', ['id'], 'pf_authority_event_fk');
        $this->create('user_external_identities', function (Blueprint $t): void {
            $t->id();
            $this->ref($t, 'user_id', 'users');
            $t->string('provider_key', 32);
            $t->string('issuer_key', 128);
            $t->char('subject_hash', 64);
            $t->unsignedInteger('hash_key_version');
            $t->dateTime('verified_at', 6);
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['provider_key', 'issuer_key', 'subject_hash'], 'pf_external_subject_uq');
        });
        $this->create('owner_trial_claim_subjects', function (Blueprint $t): void {
            $t->id();
            $this->ref($t, 'claim_id', 'owner_trial_claims');
            $t->enum('subject_kind', ['email', 'provider']);
            $t->string('issuer_key', 128);
            $t->char('subject_hash', 64);
            $t->unsignedInteger('hash_key_version');
            $t->dateTime('retained_until', 6);
            $t->unique(['subject_kind', 'issuer_key', 'subject_hash'], 'pf_trial_subject_uq');
        });
        $this->create('workspace_suspension_cycles', function (Blueprint $t): void {
            $this->identity($t);
            $this->ref($t, 'workspace_id', 'workspaces');
            $t->unsignedBigInteger('source_period_id');
            $t->foreign(['source_period_id', 'workspace_id'], 'pf_cycle_period_fk')->references(['id', 'workspace_id'])->on('subscription_periods')->restrictOnDelete();
            $t->unsignedBigInteger('source_change_id')->nullable();
            $t->foreign(['source_change_id', 'workspace_id'], 'pf_cycle_change_fk')->references(['id', 'workspace_id'])->on('subscription_changes')->restrictOnDelete();
            $t->dateTime('effective_at', 6);
            $t->string('reason_code', 60);
            $this->ref($t, 'target_version_id', 'plan_versions');
            $t->enum('state', ['open', 'resolved']);
            $t->json('resource_high_watermarks_json');
            $t->unsignedBigInteger('revision')->default(1);
            $t->dateTime('created_at', 6);
            $t->unique(['workspace_id', 'source_period_id', 'reason_code'], 'pf_cycle_source_uq');
            $t->unique(['id', 'workspace_id'], 'pf_cycle_tenant_uq');
            $t->index(['workspace_id', 'state', 'id'], 'pf_cycle_open_idx');
        });
        $this->create('workspace_entitlement_resource_states', function (Blueprint $t): void {
            $t->id();
            $this->ref($t, 'workspace_id', 'workspaces');
            $t->unsignedBigInteger('cycle_id');
            $t->foreign(['cycle_id', 'workspace_id'], 'pf_resource_cycle_fk')->references(['id', 'workspace_id'])->on('workspace_suspension_cycles')->restrictOnDelete();
            $t->enum('resource_kind', ['project', 'workspace_member', 'project_client', 'external_agent', 'integration_token', 'webhook_endpoint', 'integration_source']);
            $t->unsignedBigInteger('resource_id');
            $t->uuid('resource_uuid')->nullable();
            $t->enum('state', ['held', 'released']);
            $t->unsignedBigInteger('baseline_revision')->nullable();
            $t->unsignedBigInteger('reviewed_revision')->nullable();
            $t->string('reason_code', 60);
            $this->ref($t, 'confirmed_by', 'users', true);
            $t->dateTime('changed_at', 6);
            $t->unique(['cycle_id', 'resource_kind', 'resource_id'], 'pf_resource_cycle_uq');
            $t->index(['workspace_id', 'resource_kind', 'resource_id', 'state'], 'pf_resource_lookup_idx');
        });
        $this->create('workspace_execution_epochs', function (Blueprint $t): void {
            $this->ref($t, 'workspace_id', 'workspaces');
            $t->string('feature_group', 40);
            $t->unsignedBigInteger('epoch');
            $t->enum('state', ['ready', 'blocked', 'needs_confirmation']);
            $t->dateTime('blocked_at', 6)->nullable();
            $t->dateTime('resume_at', 6)->nullable();
            $t->unsignedBigInteger('confirmation_change_id')->nullable();
            $t->foreign(['confirmation_change_id', 'workspace_id'], 'pf_epoch_change_fk')->references(['id', 'workspace_id'])->on('subscription_changes')->restrictOnDelete();
            $t->unsignedBigInteger('revision')->default(1);
            $t->primary(['workspace_id', 'feature_group'], 'pf_epoch_pk');
        });
        $this->create('entitlement_client_identities', function (Blueprint $t): void {
            $this->identity($t);
            $this->ref($t, 'workspace_id', 'workspaces');
            $t->enum('provenance_kind', ['verified_source', 'contact_anchor', 'email_fallback', 'isolated']);
            $t->unsignedBigInteger('revision')->default(1);
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['id', 'workspace_id'], 'pf_identity_tenant_uq');
        });
        $this->create('entitlement_client_aliases', function (Blueprint $t): void {
            $t->id();
            $this->ref($t, 'workspace_id', 'workspaces');
            $t->enum('alias_kind', ['verified_source', 'contact_anchor', 'email']);
            $t->char('issuer_hash', 64);
            $t->char('alias_hash', 64);
            $t->unsignedBigInteger('identity_id')->nullable();
            $t->foreign(['identity_id', 'workspace_id'], 'pf_alias_identity_fk')->references(['id', 'workspace_id'])->on('entitlement_client_identities')->restrictOnDelete();
            $t->enum('state', ['usable', 'ambiguous']);
            $t->unsignedBigInteger('provenance_revision');
            $t->unique(['workspace_id', 'alias_kind', 'issuer_hash', 'alias_hash'], 'pf_alias_key_uq');
        });
        $this->create('entitlement_capacity_reservations', function (Blueprint $t): void {
            $t->id();
            $this->ref($t, 'workspace_id', 'workspaces');
            $t->string('key', 80);
            $t->foreign('key', 'pf_reservation_key_fk')->references('key')->on('entitlement_definitions')->restrictOnDelete();
            $t->enum('scope_kind', ['workspace', 'project']);
            $t->unsignedBigInteger('scope_id')->default(0);
            $t->char('identity_hash', 64);
            $t->unsignedBigInteger('developer_invitation_id');
            $t->dateTime('expires_at', 6);
            $t->enum('state', ['held', 'consumed', 'released', 'suspended']);
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['developer_invitation_id', 'key', 'scope_kind', 'scope_id'], 'pf_invitation_reservation_uq');
            $t->index(['workspace_id', 'key', 'state', 'expires_at'], 'pf_reservation_live_idx');
        });
        $this->foreign('entitlement_capacity_reservations', ['developer_invitation_id', 'workspace_id'], 'developer_invitations', ['id', 'workspace_id'], 'pf_reservation_invitation_fk', true);
        $this->create('workspace_file_usage', function (Blueprint $t): void {
            $this->identity($t);
            $this->ref($t, 'workspace_id', 'workspaces');
            $t->enum('source_kind', ['attachment', 'branding_asset']);
            $t->string('source_ref', 128);
            $t->json('components_json');
            $t->enum('state', ['stored', 'delete_pending', 'deleted']);
            $t->unsignedBigInteger('revision')->default(1);
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['workspace_id', 'source_kind', 'source_ref'], 'pf_file_source_uq');
            $t->index(['workspace_id', 'state', 'id'], 'pf_file_usage_idx');
        });
        $this->create('workspace_storage_reservations', function (Blueprint $t): void {
            $this->identity($t);
            $this->ref($t, 'workspace_id', 'workspaces');
            $t->char('operation_key_hash', 64);
            $t->char('request_hash', 64);
            $t->unsignedBigInteger('bytes_reserved');
            $t->unsignedBigInteger('files_reserved');
            $t->enum('state', ['held', 'committed', 'cleanup_pending', 'released']);
            $t->uuid('lease_token')->nullable();
            $t->dateTime('lease_expires_at', 6)->nullable();
            $t->dateTime('expires_at', 6)->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->unique(['workspace_id', 'operation_key_hash'], 'pf_storage_operation_uq');
            $t->index(['workspace_id', 'state', 'expires_at'], 'pf_storage_live_idx');
        });
        $this->create('plan_previews', function (Blueprint $t): void {
            $this->identity($t);
            $this->ref($t, 'actor_user_id', 'users', true);
            $t->unsignedBigInteger('authority_revision');
            $t->string('kind', 40);
            $t->uuid('target_uuid');
            $t->unsignedBigInteger('target_revision');
            $t->char('target_hash', 64);
            $t->unsignedBigInteger('settings_revision');
            $t->unsignedBigInteger('registry_revision');
            $t->json('policy_json');
            $t->enum('state', ['pending', 'building', 'ready', 'stale', 'failed']);
            $t->json('summary_json');
            $t->json('checkpoint_json')->nullable();
            $t->uuid('lease_token')->nullable();
            $t->dateTime('lease_expires_at', 6)->nullable();
            $t->dateTime('ready_at', 6)->nullable();
            $t->dateTime('expires_at', 6)->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->index(['state', 'expires_at', 'id'], 'pf_preview_state_idx');
        });
        $this->foreign('subscription_changes', ['preview_id'], 'plan_previews', ['id'], 'pf_change_preview_fk');
        $this->create('plan_preview_items', function (Blueprint $t): void {
            $this->ref($t, 'preview_id', 'plan_previews');
            $this->ref($t, 'workspace_id', 'workspaces');
            $t->unsignedBigInteger('subscription_revision');
            $t->char('usage_fingerprint', 64);
            $t->json('execution_epochs_json');
            $t->unsignedBigInteger('suspension_revision');
            $t->dateTime('evaluated_at', 6);
            $t->json('result_json');
            $t->enum('apply_state', ['pending', 'applied', 'stale', 'failed', 'skipped']);
            $t->unsignedBigInteger('change_id')->nullable();
            $t->foreign(['change_id', 'workspace_id'], 'pf_preview_change_fk')->references(['id', 'workspace_id'])->on('subscription_changes')->restrictOnDelete();
            $t->string('failure_code', 60)->nullable();
            $t->primary(['preview_id', 'workspace_id'], 'pf_preview_item_pk');
            $t->index(['preview_id', 'apply_state', 'workspace_id'], 'pf_preview_apply_idx');
        });
        $this->create('plan_notification_outbox', function (Blueprint $t): void {
            $t->id();
            $this->ref($t, 'workspace_id', 'workspaces');
            $t->uuid('period_uuid')->nullable();
            $t->uuid('cycle_uuid')->nullable();
            $t->string('notice_kind', 40);
            $t->dateTime('due_at', 6);
            $t->enum('delivery_state', ['pending', 'sent', 'failed', 'cancelled']);
            $t->char('dedupe_hash', 64)->unique('pf_notice_dedupe_uq');
            $t->json('metadata_json');
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
            $t->index(['delivery_state', 'due_at', 'id'], 'pf_notice_due_idx');
        });
        $this->foreign('plan_notification_outbox', ['period_uuid', 'workspace_id'], 'subscription_periods', ['uuid', 'workspace_id'], 'pf_notice_period_fk', true);
        $this->foreign('plan_notification_outbox', ['cycle_uuid', 'workspace_id'], 'workspace_suspension_cycles', ['uuid', 'workspace_id'], 'pf_notice_cycle_fk', true);
        $this->column('project_clients', 'entitlement_identity_id', fn (Blueprint $t) => $t->unsignedBigInteger('entitlement_identity_id')->nullable());
        $this->foreign('project_clients', ['entitlement_identity_id'], 'entitlement_client_identities', ['id'], 'pf_client_identity_fk');
        foreach (['agent_runs', 'webhook_events', 'webhook_deliveries', 'integration_requests'] as $table) {
            $this->column($table, 'execution_epoch', fn (Blueprint $t) => $t->unsignedBigInteger('execution_epoch')->nullable());
            $this->column($table, 'execution_feature_group', fn (Blueprint $t) => $t->string('execution_feature_group', 40)->nullable());
        }
        foreach (['workspace_id', 'file_usage_id'] as $field) {
            $this->column('project_file_deletions', $field, fn (Blueprint $t) => $t->unsignedBigInteger($field)->nullable());
        }
        $this->column('project_file_deletions', 'component_ref', fn (Blueprint $t) => $t->string('component_ref', 128)->nullable());
        $this->column('project_file_deletions', 'verified_deletion_receipt_json', fn (Blueprint $t) => $t->json('verified_deletion_receipt_json')->nullable());
        $this->foreign('project_file_deletions', ['workspace_id'], 'workspaces', ['id'], 'pf_cleanup_workspace_fk');
        $this->foreign('project_file_deletions', ['file_usage_id', 'workspace_id'], 'workspace_file_usage', ['id', 'workspace_id'], 'pf_cleanup_usage_fk', true);
        if (DB::getDriverName() === 'mysql') {
            foreach ([
                ['projects', ['workspace_id', 'deleted_at', 'status', 'id'], 'pf_projects_usage_idx'],
                ['workspace_members', ['workspace_id', 'status', 'role', 'user_id'], 'pf_members_usage_idx'],
                ['project_clients', ['project_id', 'active', 'entitlement_identity_id', 'id'], 'pf_clients_usage_idx'],
                ['developer_invitations', ['workspace_id', 'expires_at', 'id'], 'pf_invitation_expiry_idx'],
            ] as [$table, $columns, $name]) {
                $covered = collect(Schema::getIndexes($table))->contains(fn (array $index): bool => array_slice($index['columns'], 0, count($columns)) === $columns);
                if (! $covered) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
        $this->immutability();
    }

    private function identity(Blueprint $t): void
    {
        $t->id();
        $t->uuid('uuid')->unique('pf_'.substr(sha1($t->getTable()), 0, 12).'_uuid_uq');
    }

    private function ref(Blueprint $t, string $column, string $target, bool $nullable = false): void
    {
        $c = $t->unsignedBigInteger($column);
        if ($nullable) {
            $c->nullable();
        }
        $foreign = $t->foreign($column, 'pf_'.substr(sha1($t->getTable().$column), 0, 16).'_fk')->references('id')->on($target);
        if ($nullable && $target === 'users' && $column !== 'primary_user_id') {
            $foreign->nullOnDelete();
        } else {
            $foreign->restrictOnDelete();
        }
    }

    private function actors(Blueprint $t): void
    {
        foreach (['created_by', 'updated_by', 'published_by'] as $column) {
            $this->ref($t, $column, 'users', true);
        }
    }

    private function workspaceSubscription(Blueprint $t): void
    {
        $this->ref($t, 'workspace_id', 'workspaces');
        $t->unsignedBigInteger('subscription_id');
        $t->foreign(['subscription_id', 'workspace_id'], 'pf_'.substr(sha1($t->getTable().'subscription'), 0, 16).'_fk')->references(['id', 'workspace_id'])->on('workspace_subscriptions')->restrictOnDelete();
    }

    private function create(string $name, Closure $definition): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $definition);
        } elseif (DB::getDriverName() === 'mysql') {
            // Laravel emits table, indexes and FKs as separate DDL statements. Resume each committed step.
            $blueprint = new Blueprint(DB::connection(), $name);
            $definition($blueprint);
            foreach ($blueprint->getColumns() as $column) {
                foreach (['primary', 'unique', 'index'] as $kind) {
                    if ($column->{$kind}) {
                        $blueprint->{$kind}([$column->name], is_string($column->{$kind}) ? $column->{$kind} : null);
                    }
                }
            }
            $indexes = array_map('strtolower', array_column(Schema::getIndexes($name), 'name'));
            foreach ($blueprint->getCommands() as $command) {
                if (in_array($command->name, ['primary', 'unique', 'index'], true) && ! in_array($command->name === 'primary' ? 'primary' : strtolower($command->index), $indexes, true)) {
                    Schema::table($name, fn (Blueprint $t) => $t->{$command->name}($command->columns, $command->index));
                }
            }
            $foreignKeys = array_column(Schema::getForeignKeys($name), 'name');
            foreach ($blueprint->getCommands() as $command) {
                if ($command->name === 'foreign' && ! in_array($command->index, $foreignKeys, true)) {
                    Schema::table($name, fn (Blueprint $t) => $t->foreign($command->columns, $command->index)->references($command->references)->on($command->on)->onDelete($command->onDelete ?? 'restrict'));
                }
            }
        }
    }

    private function column(string $table, string $column, Closure $definition): void
    {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, $definition);
        }
    }

    private function foreign(string $table, array $columns, string $target, array $references, string $name, bool $addUniqueTarget = false): void
    {
        if ($addUniqueTarget) {
            $index = 'pf_'.substr(sha1($target.implode('_', $references)), 0, 16).'_uq';
            if (! Schema::hasIndex($target, $index)) {
                Schema::table($target, fn (Blueprint $t) => $t->unique($references, $index));
            }
        }
        if (! collect(Schema::getForeignKeys($table))->contains('name', $name)) {
            Schema::table($table, fn (Blueprint $t) => $t->foreign($columns, $name)->references($references)->on($target)->restrictOnDelete());
        }
    }

    private function immutability(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        foreach (['plan_versions', 'platform_policy_versions', 'subscription_periods', 'plan_events'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = 'pf_'.substr(sha1($table.$operation), 0, 16).'_tr';
                $condition = in_array($table, ['plan_versions', 'platform_policy_versions'], true) ? "OLD.state = 'published'" : '1=1';
                if ($operation === 'UPDATE') {
                    $changes = [];
                    foreach (Schema::getColumnListing($table) as $column) {
                        $changed = $mysql ? "NOT (NEW.`$column` <=> OLD.`$column`)" : "NEW.`$column` IS NOT OLD.`$column`";
                        if (in_array($column, ['created_by', 'updated_by', 'published_by', 'approved_by', 'actor_user_id'], true)) {
                            $changed = "(NEW.`$column` IS NOT NULL AND ($changed))";
                        }
                        $changes[] = $changed;
                    }
                    $condition .= ' AND ('.implode(' OR ', $changes).')';
                }
                $this->trigger($name, $table, $operation, $condition, $mysql);
            }
        }
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
            $parent = $operation === 'DELETE' ? 'OLD' : 'NEW';
            $condition = "EXISTS (SELECT 1 FROM plan_versions WHERE id = $parent.plan_version_id AND state = 'published')";
            if ($operation === 'UPDATE') {
                $condition .= " OR EXISTS (SELECT 1 FROM plan_versions WHERE id = OLD.plan_version_id AND state = 'published')";
            }
            $this->trigger('pf_values_'.strtolower($operation).'_tr', 'plan_entitlement_values', $operation, $condition, $mysql);
        }
        foreach (['INSERT', 'UPDATE'] as $operation) {
            $this->trigger('pf_rule_'.strtolower($operation).'_tr', 'plan_transition_rules', $operation, 'NEW.source_version_id = NEW.target_version_id', $mysql);
        }
        $this->trigger('pf_plan_code_tr', 'plans', 'UPDATE', "OLD.code <> NEW.code AND EXISTS (SELECT 1 FROM plan_versions WHERE plan_id = OLD.id AND state = 'published')", $mysql);
        foreach (['platform_plan_authority', 'plan_settings'] as $table) {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $this->trigger('pf_'.substr(sha1($table.$operation), 0, 16).'_single_tr', $table, $operation, 'NEW.id <> 1', $mysql);
            }
        }
    }

    private function trigger(string $name, string $table, string $operation, string $condition, bool $mysql): void
    {
        if ($mysql) {
            if (DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $name)->exists()) {
                return;
            }
            DB::unprepared("CREATE TRIGGER `$name` BEFORE $operation ON `$table` FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected plan history or singleton'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS $name BEFORE $operation ON $table WHEN $condition BEGIN SELECT RAISE(ABORT, 'Protected plan history or singleton'); END");
        }
    }

    public function down(): void
    {
        throw new LogicException('Keep additive plan history during runtime rollback; use a reviewed restore procedure.');
    }
};
