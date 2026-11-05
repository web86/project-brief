<script setup>
import { ref, watch, onBeforeUnmount, computed, nextTick } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import LoadingButton from './LoadingButton.vue'
import FileUploader from './FileUploader.vue'
import AgentChatMessage from './AgentChatMessage.vue'
import AppIcon from './AppIcon.vue'
const props = defineProps({ task: Object, viewer: String })
const collapsed = ref(false)
const unread = ref(false)
let latestMessages
const panelId = computed(() => 'agent-conversation-' + props.task.id)
const preference = computed(() =>
  props.viewer ? `dev2done.agent-collapsed.${props.viewer}.${props.task.id}` : null,
)
function toggle() {
  collapsed.value = !collapsed.value
  if (!collapsed.value) {
    unread.value = false
    nextTick(() => messageLogs.value.forEach((log) => (log.scrollTop = log.scrollHeight)))
  }
  try {
    if (preference.value) sessionStorage.setItem(preference.value, collapsed.value ? '1' : '0')
  } catch {
    // The panel still works without browser storage.
  }
}
const currentRun = computed(() => {
  const runs = rows.value.filter((row) => !row.revokedAt).flatMap((row) => row.runs.slice(0, 1))
  return runs.find((run) => ['running', 'queued'].includes(run.state)) || runs[0]
})
function runState(run) {
  return t(
    'collaboration.state.' + (run.state === 'queued' && run.deliveredAt ? 'delivered' : run.state),
  )
}
const emit = defineEmits(['changed', 'share'])
const rows = ref([]),
  legacy = ref([]),
  legacyCursor = ref(null),
  drafts = ref({}),
  files = ref({}),
  processing = ref(false),
  error = ref(''),
  busy = ref(false)
let revision = 0,
  loadRevision = 0
const messageLogs = ref([])
const keys = new Map()
const title = computed(() =>
  t('agentUx.chat', {
    name:
      (rows.value.filter((row) => !row.revokedAt).length
        ? rows.value.filter((row) => !row.revokedAt)
        : rows.value
      )
        .map((row) => row.agent.name)
        .filter((name, index, names) => names.indexOf(name) === index)
        .join(', ') ||
      props.task.agentAssignments?.map((assignment) => assignment.agent.name).join(', '),
  }),
)
async function load() {
  const follow = messageLogs.value.map(
    (log) => log.scrollHeight - log.scrollTop - log.clientHeight < 60,
  )
  const current = revision,
    attempt = ++loadRevision
  try {
    const result = await api.request(`/api/admin/tasks/${props.task.id}/agent-collaboration`)
    if (current === revision && attempt === loadRevision) {
      rows.value = result.data
      legacy.value = result.legacyMessages?.data || []
      legacyCursor.value = result.legacyMessages?.nextCursor
      const ids = new Set(
        [...legacy.value, ...rows.value.flatMap((row) => row.messages)]
          .filter((message) => message.author === 'agent')
          .map((message) => message.id),
      )
      if (collapsed.value && latestMessages && [...ids].some((id) => !latestMessages.has(id))) {
        unread.value = true
      }
      latestMessages = ids
      await nextTick()
      messageLogs.value.forEach((log, index) => {
        if (follow[index] !== false) log.scrollTop = log.scrollHeight
      })
    }
  } catch (cause) {
    if (current === revision) error.value = formatApiError(cause)
  }
}
watch(
  [() => props.task.id, () => props.viewer],
  () => {
    collapsed.value = false
    unread.value = false
    latestMessages = null
    try {
      if (preference.value) collapsed.value = sessionStorage.getItem(preference.value) === '1'
    } catch {
      // The panel still works without browser storage.
    }
    revision++
    loadRevision++
    busy.value = false
    rows.value = []
    legacy.value = []
    drafts.value = {}
    files.value = {}
    keys.clear()
    error.value = ''
    load()
  },
  { immediate: true },
)
watch(
  () =>
    JSON.stringify([
      props.task.updatedAt,
      props.task.comments?.length,
      props.task.agentAssignments?.map((row) => `${row.id}:${row.state}`),
    ]),
  load,
)
onBeforeUnmount(() => revision++)
async function send(row) {
  if (
    busy.value ||
    processing.value ||
    (!drafts.value[row.id]?.trim() && !files.value[row.id]?.length)
  )
    return
  const current = revision,
    text = drafts.value[row.id] || '',
    attachments = files.value[row.id] || []
  const signature = JSON.stringify([text.trim(), attachments.map((file) => file.id)])
  if (keys.get(row.id)?.signature !== signature)
    keys.set(row.id, { id: crypto.randomUUID(), signature })
  busy.value = true
  error.value = ''
  const body = attachments.length
    ? new FormData()
    : { instruction: text, submissionId: keys.get(row.id).id }
  if (attachments.length) {
    body.append('instruction', text)
    body.append('submissionId', keys.get(row.id).id)
    attachments.forEach((file) => body.append('attachments[]', file.file))
  }
  try {
    await api.request(`/api/admin/tasks/${props.task.id}/agent-assignments/${row.id}/runs`, {
      method: 'POST',
      body,
    })
    if (current === revision) {
      drafts.value[row.id] = ''
      files.value[row.id] = []
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
async function more(row) {
  if (busy.value) return
  const current = revision
  busy.value = true
  try {
    const url = row
      ? `/api/admin/tasks/${props.task.id}/agent-assignments/${row.id}/messages?before=${row.messagesCursor}`
      : `/api/admin/tasks/${props.task.id}/agent-collaboration/history?before=${legacyCursor.value}`
    const result = await api.request(url)
    if (current === revision) {
      if (row) {
        row.messages.unshift(...result.data)
        row.messagesCursor = result.nextCursor
      } else {
        legacy.value.unshift(...result.data)
        legacyCursor.value = result.nextCursor
      }
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
  <section class="surface agent-conversation" data-agent-chat>
    <div class="agent-chat-heading">
      <h3>
        <button
          type="button"
          class="agent-chat-toggle"
          :aria-controls="panelId"
          :aria-expanded="!collapsed"
          @click="toggle"
        >
          <AppIcon name="chevron" :size="16" :class="{ expanded: !collapsed }" />
          {{ title }}
        </button>
      </h3>
      <span v-if="collapsed && currentRun" role="status" class="small">{{
        runState(currentRun)
      }}</span>
      <span v-if="collapsed && unread" role="status" class="count-pill">{{
        t('planRestrictions.unread')
      }}</span>
      <button v-if="!collapsed" class="text-button" :disabled="busy" @click="load">
        {{ t('liveSync.checkMessages') }}
      </button>
    </div>
    <div :id="panelId" v-show="!collapsed">
      <p class="small muted">{{ t('collaboration.privateHint') }}</p>
      <p v-if="error" class="field-error" role="alert">{{ error }}</p>
      <button v-if="legacyCursor" class="text-button" :disabled="busy" @click="more(null)">
        {{ t('collaboration.moreMessages') }}
      </button>
      <div v-if="legacy.length" class="agent-message-list">
        <AgentChatMessage
          v-for="message in legacy"
          :key="message.id"
          :message="message"
          :can-share="task.canShareAgentResults"
          @share="emit('share', $event)"
        />
      </div>
      <section v-for="row in rows" :key="row.id" class="agent-thread">
        <div class="agent-chat-heading">
          <h4>{{ row.agent.name }}</h4>
          <span v-if="row.revokedAt" class="small muted">{{ t('agentUx.readOnly') }}</span
          ><span v-else-if="row.runs[0]" class="small" role="status">{{
            t(
              'collaboration.state.' +
                (row.runs[0].state === 'queued' && row.runs[0].deliveredAt
                  ? 'delivered'
                  : row.runs[0].state),
            )
          }}</span>
        </div>
        <button v-if="row.messagesCursor" class="text-button" :disabled="busy" @click="more(row)">
          {{ t('collaboration.moreMessages') }}
        </button>
        <div
          ref="messageLogs"
          class="agent-message-list"
          role="log"
          tabindex="0"
          aria-live="polite"
        >
          <AgentChatMessage
            v-for="message in row.messages"
            :key="message.id"
            :message="message"
            :name="row.agent.name"
            :can-share="task.canShareAgentResults"
            @share="emit('share', $event)"
          />
        </div>
        <template v-if="!row.revokedAt && task.canShareAgentResults">
          <form class="agent-follow-up" @submit.prevent="send(row)">
            <label :for="'agent-follow-up-' + row.id">{{ t('collaboration.instruction') }}</label
            ><textarea
              :id="'agent-follow-up-' + row.id"
              v-model="drafts[row.id]"
              rows="3"
              maxlength="5000"
              :disabled="busy"
            />
            <FileUploader
              v-model="files[row.id]"
              compact
              :max-files="5"
              :max-total-size="40 * 1024 * 1024"
              :disabled="busy"
              @busy="processing = $event"
            />
            <LoadingButton
              type="submit"
              class="button secondary"
              :busy="busy"
              :disabled="
                busy ||
                processing ||
                (!drafts[row.id]?.trim() && !files[row.id]?.length) ||
                row.runs.some((run) => ['queued', 'running'].includes(run.state))
              "
              >{{ t('collaboration.requestRun') }}</LoadingButton
            >
          </form>
          <details
            v-if="row.runs.some((run) => ['queued', 'running'].includes(run.state))"
            class="agent-execution-actions"
          >
            <summary>{{ t('agentUx.executionActions') }}</summary>
            <button
              class="text-button danger"
              :disabled="busy"
              @click="cancel(row.runs.find((run) => ['queued', 'running'].includes(run.state)))"
            >
              {{ t('ui.cancel') }}
            </button>
          </details>
        </template>
      </section>
    </div>
  </section>
</template>
<style scoped>
.agent-conversation {
  padding: 16px;
  min-width: 0;
}
.agent-chat-heading {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px 16px;
}
h3,
h4 {
  margin: 0;
  font-size: 1rem;
  overflow-wrap: anywhere;
}
.agent-chat-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  text-align: start;
  background: transparent;
  border: 0;
  color: inherit;
  font: inherit;
  min-height: 36px;
  cursor: pointer;
}
.agent-chat-toggle svg {
  flex: none;
}
.agent-chat-toggle svg.expanded {
  transform: rotate(90deg);
}
.agent-thread {
  margin-top: 18px;
  border-top: 1px solid var(--border);
  padding-top: 16px;
}
.agent-message-list {
  max-height: min(65dvh, 640px);
  overflow-y: auto;
  scrollbar-gutter: stable;
  display: grid;
  gap: 12px;
  margin-block: 12px;
}
.agent-follow-up {
  display: grid;
  gap: 10px;
}
.agent-follow-up textarea {
  min-height: 88px;
}
.agent-follow-up > .button {
  justify-self: start;
}
.agent-execution-actions {
  margin-top: 12px;
  font-size: 0.8rem;
}
.agent-execution-actions summary {
  cursor: pointer;
  color: var(--muted);
}
</style>
