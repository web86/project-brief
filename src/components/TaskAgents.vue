<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { agentIcon, latestAgentReport } from '../services/agentPresentation.js'
import { formatDate } from '../constants/project.js'
import AppIcon from './AppIcon.vue'
import BaseDialog from './BaseDialog.vue'
import AttachmentList from './AttachmentList.vue'
const report = ref(null)
const props = defineProps({ task: Object, projectId: String, agents: Array })
const emit = defineEmits(['changed', 'share'])
const available = computed(() => props.agents || []),
  selected = ref(''),
  instruction = ref(''),
  busy = ref(false),
  error = ref('')
const assignments = computed(() =>
  (props.task.agentAssignments || []).map((row) => ({
    ...row,
    latestReport: latestAgentReport(props.task, row),
  })),
)
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
function shareReport() {
  emit('share', report.value)
  report.value = null
}
async function assign(agentId, method) {
  if (busy.value) return
  const version = revision
  busy.value = true
  error.value = ''
  try {
    await api.request(`/api/admin/tasks/${props.task.id}/agents`, {
      method,
      body: { agentId, ...(method === 'POST' ? { instruction: instruction.value } : {}) },
    })
    if (version === revision) {
      selected.value = ''
      instruction.value = ''
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
    <h3><AppIcon name="bot" :size="18" /> {{ t('agents.executor') }}</h3>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <article v-for="row in assignments" :key="row.id" class="agent-assignment">
      <div class="agent-heading">
        <AppIcon :name="agentIcon(row.agent.kind)" :size="18" /><strong>{{ row.agent.name }}</strong
        ><span class="small muted">{{ t('agents.kind.' + row.agent.kind) }}</span
        ><span v-if="!row.agent.active" class="status-pill">{{ t('agents.disabled') }}</span
        ><button
          class="text-button danger agent-unassign"
          :disabled="busy"
          @click="assign(row.agent.id, 'DELETE')"
        >
          {{ t('agents.unassign') }}
        </button>
      </div>
      <p v-if="row.agent.hasCredentials === false" class="small agent-connection-needed">
        <AppIcon name="key" :size="14" /> {{ t('agents.requiresConnection') }}
      </p>
      <p class="agent-execution">
        {{ t(row.agent.hasCredentials === false ? 'agents.lastExecution' : 'agents.execution') }}:
        <strong>{{ t('agents.executionState.' + row.state) }}</strong>
      </p>
      <div v-if="row.latestReport" class="agent-latest-report">
        <div class="agent-report-meta">
          <span v-if="row.latestReport.teamOnly" class="agent-internal-label"
            ><AppIcon name="lock" :size="14" />{{ t('agents.internalResult') }}</span
          >
          <span
            >{{ t('agents.latestReport') }} ·
            {{ t('agents.state.' + row.latestReport.state) }}</span
          ><time :datetime="row.latestReport.createdAt">{{
            formatDate(row.latestReport.createdAt)
          }}</time>
        </div>
        <p v-if="row.latestReport.summary" class="agent-report-preview">
          {{ row.latestReport.summary }}
        </p>
        <div class="agent-result">
          <a
            v-if="row.latestReport.pullRequestUrl"
            :href="row.latestReport.pullRequestUrl"
            target="_blank"
            rel="noopener noreferrer"
            >{{ t('agents.pullRequest') }}</a
          ><a
            v-if="row.latestReport.resultUrl"
            :href="row.latestReport.resultUrl"
            target="_blank"
            rel="noopener noreferrer"
            >{{ t('agents.result') }}</a
          ><button
            v-if="row.latestReport.commentId"
            class="text-button"
            @click="
              report = task.comments.find((comment) => comment.id === row.latestReport.commentId)
            "
          >
            {{ t('agents.fullReport') }}
          </button>
          <button
            v-if="
              task.canShareAgentResults && row.latestReport.teamOnly && row.latestReport.commentId
            "
            class="text-button"
            @click="
              emit(
                'share',
                task.comments.find((comment) => comment.id === row.latestReport.commentId),
              )
            "
          >
            {{ t('agents.shareClient') }}
          </button>
        </div>
      </div>
      <p v-else-if="row.agent.lastActivityAt" class="small muted">
        {{ t('agents.lastActivity') }}: {{ formatDate(row.agent.lastActivityAt) }}
      </p>
    </article>
    <form
      v-if="choices.length"
      class="agent-assign-form"
      @submit.prevent="assign(selected, 'POST')"
    >
      <label class="field"
        >{{ t('agents.choose')
        }}<select v-model="selected" required :disabled="busy">
          <option value="" disabled>{{ t('agents.choose') }}</option>
          <option v-for="agent in choices" :key="agent.id" :value="agent.id">
            {{ agent.name }}
          </option>
        </select></label
      >
      <label class="field agent-instruction-field"
        >{{ t('collaboration.instruction')
        }}<textarea v-model="instruction" rows="2" maxlength="5000" :disabled="busy" /></label
      ><button class="button secondary" :disabled="busy || !selected">
        {{ t('agents.assignAction') }}
      </button>
    </form>
    <p v-if="assignments.length" class="small muted agent-visibility-note">
      {{ t('agents.internalResultsHint') }}
    </p>
    <p v-if="!assignments.length && !choices.length" class="small muted">
      {{ t('agents.noExecutors') }}
    </p>
    <BaseDialog
      :open="!!report"
      :title="t('agents.fullReport')"
      id="agent-full-report"
      @close="report = null"
      ><div class="agent-full-report-body">
        <p class="preserve-lines">{{ report?.text }}</p>
        <AttachmentList v-if="report?.attachments?.length" :attachments="report.attachments" />
      </div>
      <template #footer
        ><button class="button secondary" @click="report = null">{{ t('ai.close') }}</button
        ><button
          v-if="task.canShareAgentResults && report?.teamOnly"
          class="button primary"
          @click="shareReport"
        >
          {{ t('agents.shareClient') }}
        </button></template
      ></BaseDialog
    >
  </section>
</template>
<style scoped>
.agent-connection-needed {
  display: flex;
  gap: 6px;
  align-items: center;
  margin: 6px 0;
}
.agent-internal-label {
  display: inline-flex;
  gap: 4px;
  align-items: center;
}
.task-agent-panel h3 {
  margin: 0 0 8px;
  font-size: 1rem;
}
.agent-unassign {
  margin-inline-start: auto;
}
.agent-execution {
  margin: 6px 0;
  font-size: 0.875rem;
}
.agent-report-meta {
  display: flex;
  gap: 6px 12px;
  flex-wrap: wrap;
  color: var(--muted);
  font-size: 0.75rem;
}
.agent-report-preview {
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
  overflow-wrap: anywhere;
  font-size: 0.85rem;
  margin: 6px 0;
}
.agent-visibility-note {
  margin: 8px 0 0;
  font-size: 0.75rem;
}
.agent-assignment:last-of-type {
  border-bottom: 0;
}

.task-agent-panel {
  padding: 14px 16px;
  margin-block: 12px;
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
  margin-top: 8px;
  align-items: end;
}
.agent-assign-form .field {
  flex: 1;
  min-width: 0;
  margin: 0;
}
</style>

<style scoped>
.agent-instruction-field {
  flex-basis: 100% !important;
}
</style>

<style scoped>
.agent-full-report-body {
  padding: 16px 24px;
  max-height: 60dvh;
  overflow: auto;
  overflow-wrap: anywhere;
}
</style>
