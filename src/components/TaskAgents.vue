<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { agentIcon } from '../services/agentPresentation.js'
import AppIcon from './AppIcon.vue'
import LoadingButton from './LoadingButton.vue'
const props = defineProps({ task: Object, projectId: String, agents: Array })
const emit = defineEmits(['changed'])
const selected = ref(''),
  busy = ref(false),
  error = ref('')
const assignments = computed(() => props.task.agentAssignments || [])
const choices = computed(() =>
  (props.agents || []).filter(
    (agent) => agent.active && !assignments.value.some((row) => row.agent.id === agent.id),
  ),
)
let revision = 0
watch(
  () => [props.projectId, props.task.id],
  () => {
    revision++
    selected.value = ''
    error.value = ''
    busy.value = false
  },
)
onBeforeUnmount(() => revision++)
async function assign(agentId, method) {
  if (busy.value || !agentId) return
  const version = revision
  busy.value = true
  error.value = ''
  try {
    await api.request(`/api/admin/tasks/${props.task.id}/agents`, {
      method,
      body: { agentId, ...(method === 'POST' ? { deferRun: true, replace: true } : {}) },
    })
    if (version === revision) {
      selected.value = ''
      emit('changed')
    }
  } catch (cause) {
    if (version === revision) error.value = formatApiError(cause)
  } finally {
    if (version === revision) busy.value = false
  }
}
</script>
<template>
  <section class="surface task-agent-panel" data-task-agents>
    <h3><AppIcon name="bot" :size="18" />{{ t('agentUx.agent') }}</h3>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <article v-for="row in assignments" :key="row.id" class="agent-compact-row">
      <div class="agent-compact-heading">
        <AppIcon :name="agentIcon(row.agent.kind)" :size="17" /><strong>{{
          row.agent.name
        }}</strong>
      </div>
      <p class="small" role="status" :title="t('collaboration.privateHint')">
        <span class="status-pill">{{
          t(
            row.agent.hasCredentials === false
              ? 'agents.requiresConnection'
              : 'agents.executionState.' + row.state,
          )
        }}</span>
      </p>
      <button
        v-if="task.canShareAgentResults"
        class="text-button danger"
        :disabled="busy"
        @click="assign(row.agent.id, 'DELETE')"
      >
        {{ t('agents.unassign') }}
      </button>
    </article>
    <form
      v-if="task.canShareAgentResults && choices.length"
      class="agent-assign-form"
      @submit.prevent="assign(selected, 'POST')"
    >
      <label class="field" :for="'task-agent-choice-' + task.id"
        >{{ t(assignments.length ? 'agentUx.chooseAnother' : 'agents.choose')
        }}<select :id="'task-agent-choice-' + task.id" v-model="selected" required :disabled="busy">
          <option value="" disabled>{{ t('agents.choose') }}</option>
          <option v-for="agent in choices" :key="agent.id" :value="agent.id">
            {{ agent.name }}
          </option>
        </select></label
      >
      <LoadingButton
        type="submit"
        class="button secondary"
        :busy="busy"
        :disabled="busy || !selected"
        >{{ t('agents.assignAction') }}</LoadingButton
      >
    </form>
  </section>
</template>
<style scoped>
.task-agent-panel {
  padding: 16px;
  min-width: 0;
}
h3 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 12px;
  font-size: 1rem;
}
.agent-compact-row + .agent-compact-row {
  border-top: 1px solid var(--border);
  padding-top: 12px;
  margin-top: 12px;
}
.agent-compact-heading {
  display: flex;
  align-items: center;
  gap: 8px;
  overflow-wrap: anywhere;
}
.agent-compact-row p {
  margin: 8px 0;
}
.agent-assign-form {
  display: grid;
  gap: 10px;
  margin-top: 12px;
}
.agent-assign-form .field {
  margin: 0;
  min-width: 0;
}
</style>
