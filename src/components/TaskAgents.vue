<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
const props = defineProps({ task: Object, projectId: String, agents: Array })
const emit = defineEmits(['changed'])
const available = computed(() => props.agents || []),
  selected = ref(''),
  busy = ref(false),
  error = ref('')
const assignments = computed(() => props.task.agentAssignments || [])
const choices = computed(() =>
  available.value.filter(
    (a) => a.active && !assignments.value.some((row) => row.agent.id === a.id),
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
  if (busy.value) return
  const version = revision
  busy.value = true
  error.value = ''
  try {
    await api.request(`/api/admin/tasks/${props.task.id}/agents`, { method, body: { agentId } })
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
    <h3><AppIcon name="bot" /> {{ t('agents.executors') }}</h3>
    <p class="small muted">{{ t('agents.reviewHint') }}</p>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <article v-for="row in assignments" :key="row.id" class="agent-assignment">
      <div class="agent-heading">
        <strong>{{ row.agent.name }}</strong
        ><span class="status-pill">{{ t('agents.state.' + row.state) }}</span
        ><span class="small muted">{{
          t(row.agent.active ? 'agents.active' : 'agents.disabled')
        }}</span
        ><button
          class="text-button danger"
          :disabled="busy"
          @click="assign(row.agent.id, 'DELETE')"
        >
          {{ t('agents.unassign') }}
        </button>
      </div>
      <div v-for="report in row.reports || []" :key="report.id" class="agent-result">
        <span>{{ t('agents.state.' + report.state) }}</span
        ><a
          v-if="report.pullRequestUrl"
          :href="report.pullRequestUrl"
          target="_blank"
          rel="noopener noreferrer"
          >{{ t('agents.pullRequest') }}</a
        ><a
          v-if="report.resultUrl"
          :href="report.resultUrl"
          target="_blank"
          rel="noopener noreferrer"
          >{{ t('agents.result') }}</a
        >
      </div>
    </article>
    <form
      v-if="choices.length"
      class="agent-assign-form"
      @submit.prevent="assign(selected, 'POST')"
    >
      <label class="field"
        >{{ t('agents.assign')
        }}<select v-model="selected" required :disabled="busy">
          <option value="" disabled>{{ t('agents.choose') }}</option>
          <option v-for="agent in choices" :key="agent.id" :value="agent.id">
            {{ agent.name }}
          </option>
        </select></label
      ><button class="button secondary" :disabled="busy || !selected">
        {{ t('agents.assign') }}
      </button>
    </form>
    <p v-if="!assignments.length && !choices.length" class="small muted">
      {{ t('agents.noExecutors') }}
    </p>
  </section>
</template>
<style scoped>
.task-agent-panel {
  padding: 1.25rem;
  margin-block: 1rem;
}
.task-agent-panel h3,
.agent-heading,
.agent-result,
.agent-assign-form {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}
.agent-assignment {
  padding-block: 0.75rem;
  border-bottom: 1px solid var(--border);
}
.agent-result {
  margin-top: 0.5rem;
  font-size: 0.875rem;
}
.agent-assign-form {
  margin-top: 1rem;
  align-items: end;
}
.agent-assign-form .field {
  flex: 1;
  min-width: 0;
  margin: 0;
}
</style>
