<script setup>
import { ref, watch, onBeforeUnmount, nextTick } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { formatDate } from '../constants/project.js'
import { agentTimestamp } from '../services/agentPresentation.js'
import LoadingButton from './LoadingButton.vue'
const props = defineProps({ task: Object })
const emit = defineEmits(['changed'])
const rows = ref([]),
  drafts = ref({}),
  error = ref(''),
  busy = ref(false),
  expanded = ref(false)
const messageLogs = ref([])
let revision = 0,
  loadRevision = 0
const keys = new Map()
async function load() {
  const current = revision,
    attempt = ++loadRevision
  try {
    const result = await api.request(`/api/admin/tasks/${props.task.id}/agent-collaboration`)
    if (current === revision && attempt === loadRevision) {
      rows.value = result.data
      await nextTick()
      for (const log of messageLogs.value) log.scrollTop = log.scrollHeight
    }
  } catch (cause) {
    if (current === revision) error.value = formatApiError(cause)
  }
}
watch(
  () =>
    JSON.stringify([
      props.task.id,
      props.task.updatedAt,
      props.task.comments?.length,
      props.task.agentAssignments
        ?.map((row) => `${row.id}:${row.state}:${row.reports?.length}`)
        .join('|'),
    ]),
  () => {
    if (expanded.value) load()
  },
)
watch(
  () => props.task.id,
  () => {
    revision++
    loadRevision++
    busy.value = false
    rows.value = []
    drafts.value = {}
    keys.clear()
    expanded.value = false
    error.value = ''
  },
)
onBeforeUnmount(() => revision++)
function toggle(event) {
  expanded.value = event.target.open
  if (expanded.value) load()
}
async function send(row) {
  if (busy.value || !drafts.value[row.id]?.trim()) return
  const current = revision
  busy.value = true
  error.value = ''
  if (keys.get(row.id)?.text !== drafts.value[row.id].trim())
    keys.set(row.id, { id: crypto.randomUUID(), text: drafts.value[row.id].trim() })
  try {
    await api.request(`/api/admin/tasks/${props.task.id}/agent-assignments/${row.id}/runs`, {
      method: 'POST',
      body: { instruction: drafts.value[row.id], submissionId: keys.get(row.id).id },
    })
    if (current === revision) {
      drafts.value[row.id] = ''
      keys.delete(row.id)
      await load()
      emit('changed')
    }
  } catch (cause) {
    if (current === revision) error.value = formatApiError(cause)
  } finally {
    if (current === revision) busy.value = false
  }
}
async function moreMessages(row) {
  if (busy.value) return
  const current = revision
  busy.value = true
  try {
    const result = await api.request(
      `/api/admin/tasks/${props.task.id}/agent-assignments/${row.id}/messages?before=${encodeURIComponent(row.messagesCursor)}`,
    )
    if (current === revision) {
      row.messages.unshift(...result.data)
      row.messagesCursor = result.nextCursor
    }
  } catch (cause) {
    if (current === revision) error.value = formatApiError(cause)
  } finally {
    if (current === revision) busy.value = false
  }
}
async function moreRuns(row) {
  if (busy.value) return
  const current = revision
  busy.value = true
  try {
    const result = await api.request(
      `/api/admin/tasks/${props.task.id}/agent-assignments/${row.id}/runs?page=${row.historyPage + 1}`,
    )
    if (current === revision) {
      row.runs.push(...result.data)
      row.historyPage = result.meta.page
    }
  } catch (cause) {
    if (current === revision) error.value = formatApiError(cause)
  } finally {
    if (current === revision) busy.value = false
  }
}
async function cancel(run) {
  if (busy.value) return
  const current = revision
  busy.value = true
  try {
    await api.request(`/api/admin/tasks/${props.task.id}/agent-runs/${run.id}/cancel`, {
      method: 'POST',
      body: {},
    })
    if (current === revision) {
      await load()
      emit('changed')
    }
  } catch (cause) {
    if (current === revision) error.value = formatApiError(cause)
  } finally {
    if (current === revision) busy.value = false
  }
}
</script>
<template>
  <details class="surface agent-conversation" @toggle="toggle">
    <summary>{{ t('collaboration.dialogue') }}</summary>
    <p class="small muted">{{ t('collaboration.privateHint') }}</p>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <button class="text-button" :disabled="busy" @click="load">
      {{ t('liveSync.checkMessages') }}
    </button>
    <section v-for="row in rows" :key="row.id" class="agent-thread">
      <h4>
        {{ row.agent.name }}
        <span v-if="row.revokedAt" class="small muted">{{ t('agents.unassign') }}</span>
      </h4>
      <p v-if="row.runs[0]" class="small muted" role="status">
        {{
          t(
            'collaboration.state.' +
              (row.runs[0].state === 'queued' && row.runs[0].deliveredAt
                ? 'delivered'
                : row.runs[0].state),
          )
        }}
      </p>
      <button
        v-if="row.messagesCursor"
        class="text-button"
        :disabled="busy"
        @click="moreMessages(row)"
      >
        {{ t('collaboration.moreMessages') }}
      </button>
      <div ref="messageLogs" class="agent-dialogue-log" role="log" aria-live="polite" tabindex="0">
        <article
          v-for="message in row.messages"
          :key="message.id"
          class="agent-dialogue-message"
          :class="message.author"
        >
          <strong>{{ t('collaboration.author.' + message.author) }}</strong
          ><time class="small muted">{{ formatDate(agentTimestamp(message.createdAt)) }}</time>
          <p class="preserve-lines">{{ message.text }}</p>
        </article>
      </div>
      <form
        v-if="!row.revokedAt && task.canShareAgentResults"
        class="agent-follow-up"
        @submit.prevent="send(row)"
      >
        <label :for="'agent-follow-up-' + row.id">{{ t('collaboration.instruction') }}</label>
        <textarea
          :id="'agent-follow-up-' + row.id"
          v-model="drafts[row.id]"
          rows="3"
          maxlength="5000"
          :disabled="busy"
          required
        />
        <LoadingButton
          type="submit"
          class="button secondary"
          :busy="busy"
          :disabled="
            busy ||
            !drafts[row.id]?.trim() ||
            row.runs.some((run) => ['queued', 'running'].includes(run.state))
          "
          >{{ t('collaboration.requestRun') }}</LoadingButton
        >
      </form>
      <details class="agent-run-history">
        <summary>{{ t('collaboration.history') }} ({{ row.runCount }})</summary>
        <article v-for="run in row.runs" :key="run.id">
          <strong>{{
            t(
              'collaboration.state.' +
                (run.state === 'queued' && run.deliveredAt ? 'delivered' : run.state),
            )
          }}</strong>
          <time class="small muted">{{ formatDate(agentTimestamp(run.createdAt)) }}</time>
          <p class="preserve-lines">{{ run.instruction }}</p>
          <code class="small">{{ run.id }}</code>
          <button
            v-if="['queued', 'running'].includes(run.state) && task.canShareAgentResults"
            class="text-button danger"
            :disabled="busy"
            @click="cancel(run)"
          >
            {{ t('ui.cancel') }}
          </button>
        </article>
        <button
          v-if="row.runs.length < row.runCount"
          class="text-button"
          :disabled="busy"
          @click="moreRuns(row)"
        >
          {{ t('collaboration.moreHistory') }}
        </button>
      </details>
    </section>
  </details>
</template>
<style scoped>
.agent-conversation {
  margin-block: 12px;
  padding: 16px;
  min-width: 0;
}
.agent-conversation summary {
  cursor: pointer;
  font-weight: 600;
}
.agent-thread {
  border-top: 1px solid var(--border);
  margin-top: 16px;
  padding-top: 12px;
}
.agent-dialogue-message {
  padding: 12px;
  margin-block: 10px;
  background: var(--surface-alt, var(--surface));
  border: 1px solid var(--border);
  border-radius: 8px;
  overflow-wrap: anywhere;
}
.agent-dialogue-message time,
.agent-run-history time {
  display: block;
}
.agent-dialogue-message p {
  margin-bottom: 0;
}
.agent-follow-up {
  display: grid;
  gap: 10px;
  margin-block: 16px;
}
.agent-follow-up button {
  justify-self: start;
}
.agent-run-history article {
  padding-block: 12px;
  border-bottom: 1px solid var(--border);
  overflow-wrap: anywhere;
}
.agent-run-history code {
  display: block;
}
</style>

<style scoped>
.agent-dialogue-log {
  max-height: 22rem;
  overflow: auto;
  overscroll-behavior: contain;
  padding-inline: 2px;
}
.agent-run-history {
  max-height: 22rem;
  overflow: auto;
  margin-top: 14px;
}
</style>
