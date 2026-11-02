<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->create('plan_inventory_cohorts', function (Blueprint $t): void {
            $t->unsignedBigInteger('id')->primary();
            $t->uuid('uuid')->unique('pi_cohort_uuid_uq');
            $t->unsignedBigInteger('workspace_cutoff_id');
            $t->dateTime('captured_at', 6);
            $t->unsignedBigInteger('revision')->default(1);
        });
        $this->create('plan_inventory_items', function (Blueprint $t): void {
            $t->unsignedBigInteger('workspace_id')->primary();
            $t->foreign('workspace_id', 'pi_item_workspace_fk')->references('id')->on('workspaces')->restrictOnDelete();
            $t->unsignedBigInteger('cohort_id');
            $t->foreign('cohort_id', 'pi_item_cohort_fk')->references('id')->on('plan_inventory_cohorts')->restrictOnDelete();
            $t->enum('state', ['pending', 'processed', 'needs_review', 'failed']);
            $t->unsignedBigInteger('last_client_id')->default(0);
            $t->unsignedBigInteger('client_cutoff_id')->default(0);
            $t->unsignedBigInteger('last_invitation_id')->default(0);
            $t->unsignedBigInteger('invitation_cutoff_id')->default(0);
            $t->unsignedBigInteger('revision')->default(1);
            $t->json('reason_codes');
            $t->dateTime('observed_at', 6);
            $t->index(['state', 'workspace_id'], 'pi_item_state_idx');
        });
        $this->create('entitlement_client_observations', function (Blueprint $t): void {
            $t->unsignedBigInteger('project_client_id')->primary();
            $t->foreign('project_client_id', 'pi_observation_client_fk')->references('id')->on('project_clients')->cascadeOnDelete();
            $t->unsignedBigInteger('workspace_id');
            $t->foreign('workspace_id', 'pi_observation_workspace_fk')->references('id')->on('workspaces')->restrictOnDelete();
            $t->unsignedBigInteger('identity_id')->nullable();
            $t->foreign(['identity_id', 'workspace_id'], 'pi_observation_identity_fk')->references(['id', 'workspace_id'])->on('entitlement_client_identities')->restrictOnDelete();
            $t->char('input_hash', 64);
            $t->enum('state', ['ready', 'needs_review']);
            $t->json('reason_codes');
            $t->unsignedBigInteger('revision')->default(1);
            $t->dateTime('observed_at', 6);
            $t->index(['workspace_id', 'state', 'project_client_id'], 'pi_observation_state_idx');
        });
    }

    public function down(): void
    {
        throw new LogicException('Inventory and accounting history are forward-only.');
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
};
