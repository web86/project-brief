<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'
import { formatDate } from '../constants/project.js'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { isSecurityCancellation } from '../services/stepUp.js'
import BaseDialog from './BaseDialog.vue'
import AppIcon from './AppIcon.vue'
import LoadingButton from './LoadingButton.vue'
const nav = useAdminNavigationStore()
const state = ref(null),
  error = ref(''),
  busy = ref(false),
  open = ref(false),
  secret = ref(''),
  editing = ref(null)
const draft = ref({}),
  scopes = ref([])
let revision = 0
const root = '/api/admin/integrations/agents'
async function load(version = revision) {
  const result = await api.request(root)
  if (version === revision) state.value = result
}
watch(
  () => nav.workspace?.id,
  async () => {
    const version = ++revision
    state.value = null
    secret.value = ''
    open.value = false
    error.value = ''
    busy.value = false
    try {
      await load(version)
    } catch (cause) {
      if (version === revision) error.value = formatApiError(cause)
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  revision++
  secret.value = ''
})
function configure(agent = null) {
  editing.value = agent
  draft.value = {
    name: agent?.name || '',
    description: agent?.description || '',
    kind: agent?.kind || 'ai',
    active: agent?.active ?? true,
    projectIds: [...(agent?.projectIds || [])],
    webhookUrl: agent?.webhook?.url || '',
  }
  scopes.value = [...(state.value?.scopes || [])]
  error.value = ''
  open.value = true
}
async function action(path, method, body, close = false) {
  if (busy.value) return
  const version = revision
  busy.value = true
  error.value = ''
  try {
    const result = await api.request(root + path, { method, body })
    if (version !== revision) return
    if (close) open.value = false
    secret.value = result.secret || result.webhookSecret || ''
    await load(version)
    if (editing.value && version === revision)
      editing.value = state.value.data.find((a) => a.id === editing.value.id) || null
  } catch (cause) {
    if (version === revision && !isSecurityCancellation(cause)) error.value = formatApiError(cause)
  } finally {
    if (version === revision) busy.value = false
  }
}
function remove(agent) {
  if (window.confirm(t('agents.confirmDelete', { name: agent.name })))
    action('/' + agent.id, 'DELETE')
}
async function copySecret() {
  try {
    await navigator.clipboard.writeText(secret.value)
  } catch {
    error.value = t('agents.copyManually')
  }
}
</script>
<template>
  <section class="surface integration-list agent-settings" data-agent-settings>
    <div class="page-heading">
      <div>
        <h2>{{ t('agents.title') }}</h2>
        <p class="muted">{{ t('agents.hint') }}</p>
      </div>
      <button class="button primary" :disabled="busy || !state" @click="configure()">
        <AppIcon name="plus" :size="18" />{{ t('agents.create') }}
      </button>
    </div>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <p v-if="!state" role="status">{{ t('nav.loading') }}</p>
    <button
      v-if="!state && error"
      class="button secondary"
      @click="load().catch((cause) => (error = formatApiError(cause)))"
    >
      {{ t('ui.tryAgain') }}
    </button>
    <p v-if="state && !state.data.length" class="muted">{{ t('agents.empty') }}</p>
    <article
      v-for="agent in state?.data || []"
      :key="agent.id"
      class="team-member project-team-member agent-card"
      :data-agent-id="agent.id"
    >
      <div class="team-identity">
        <span class="task-account-avatar user-avatar agent-avatar"
          ><AppIcon name="bot" :size="24"
        /></span>
        <div>
          <strong>{{ agent.name }}</strong>
          <p class="small muted">{{ agent.description || t('agents.kind.' + agent.kind) }}</p>
        </div>
      </div>
      <div class="project-team-status">
        <span class="status-pill">{{ t(agent.active ? 'agents.active' : 'agents.disabled') }}</span
        ><span>{{ t('agents.projectsCount', { count: agent.projectCount }) }}</span
        ><span
          >{{ t('agents.lastActivity') }}:
          {{ agent.lastActivityAt ? formatDate(agent.lastActivityAt) : '—' }}</span
        ><span>{{
          t('agents.requests', { success: agent.successfulRequests, failed: agent.failedRequests })
        }}</span
        ><span v-if="agent.webhook"
          >Webhook: {{ agent.webhook.status ? t('agents.delivery.' + agent.webhook.status) : '—'
          }}<template v-if="agent.webhook.lastError">
            · {{ agent.webhook.lastError }}</template
          ></span
        >
      </div>
      <div class="team-actions">
        <button class="button secondary" :disabled="busy" @click="configure(agent)">
          {{ t('agents.configure') }}</button
        ><button class="text-button danger" :disabled="busy" @click="remove(agent)">
          {{ t('ui.delete') }}
        </button>
      </div>
    </article>
    <BaseDialog
      :open="open"
      :title="t(editing ? 'agents.configure' : 'agents.create')"
      id="agent-editor-title"
      @close="!busy && (open = false)"
    >
      <form
        id="agent-editor"
        class="agent-form"
        @submit.prevent="
          action(editing ? '/' + editing.id : '', editing ? 'PATCH' : 'POST', draft, true)
        "
      >
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <label class="field"
          >{{ t('agents.name')
          }}<input v-model="draft.name" required maxlength="100" :disabled="busy"
        /></label>
        <label class="field"
          >{{ t('agents.description')
          }}<textarea v-model="draft.description" maxlength="1000" :disabled="busy" />
        </label>
        <label class="field"
          >{{ t('agents.type')
          }}<select v-model="draft.kind" :disabled="busy">
            <option v-for="kind in ['ai', 'automation', 'service']" :key="kind" :value="kind">
              {{ t('agents.kind.' + kind) }}
            </option>
          </select></label
        >
        <fieldset class="project-choices" :disabled="busy">
          <legend>{{ t('agents.allowedProjects') }}</legend>
          <label v-for="project in state?.projects || []" :key="project.id"
            ><input v-model="draft.projectIds" type="checkbox" :value="project.id" /><span>{{
              project.name
            }}</span></label
          >
        </fieldset>
        <p class="small muted">{{ t('agents.consent') }}</p>
        <label class="field"
          >{{ t('agents.webhook')
          }}<input v-model="draft.webhookUrl" type="url" placeholder="https://" :disabled="busy"
        /></label>
        <label class="checkbox-field"
          ><input v-model="draft.active" type="checkbox" :disabled="busy" />{{
            t('agents.active')
          }}</label
        >
      </form>
      <details v-if="editing" class="agent-credentials">
        <summary>{{ t('agents.credentials') }}</summary>
        <p class="small muted">{{ t('agents.once') }}</p>
        <fieldset class="project-choices" :disabled="busy">
          <legend>{{ t('agents.scopes') }}</legend>
          <label v-for="scope in state?.scopes || []" :key="scope"
            ><input v-model="scopes" type="checkbox" :value="scope" /><span>{{
              scope
            }}</span></label
          >
        </fieldset>
        <button
          type="button"
          class="button secondary"
          :disabled="busy || !scopes.length || !editing.active"
          @click="action('/' + editing.id + '/tokens', 'POST', { scopes, replace: true }, true)"
        >
          {{ t('agents.issueToken') }}
        </button>
        <div v-for="token in editing.tokens" :key="token.id" class="agent-token-row">
          <code>{{ token.id }}</code
          ><span>{{ t(token.revokedAt ? 'agents.revoked' : 'agents.active') }}</span
          ><button
            v-if="!token.revokedAt"
            type="button"
            class="text-button danger"
            :disabled="busy"
            @click="action('/' + editing.id + '/tokens/' + token.id, 'DELETE')"
          >
            {{ t('agents.revoke') }}
          </button>
        </div>
      </details>
      <template #footer
        ><button class="button secondary" :disabled="busy" @click="open = false">
          {{ t('ui.cancel') }}</button
        ><LoadingButton
          form="agent-editor"
          type="submit"
          class="button primary"
          :busy="busy"
          :disabled="busy"
          >{{ t('ui.save') }}</LoadingButton
        ></template
      >
    </BaseDialog>
    <BaseDialog
      :open="!!secret"
      :title="t('agents.credentials')"
      id="agent-secret-title"
      @close="secret = ''"
      ><p>{{ t('agents.once') }}</p>
      <textarea
        :value="secret"
        readonly
        class="agent-secret"
        :aria-label="t('agents.credentials')"
        spellcheck="false"
        autocomplete="off"
      /><template #footer
        ><button class="button secondary" @click="copySecret">{{ t('integrations.copy') }}</button
        ><button class="button primary" @click="secret = ''">
          {{ t('ui.closeDialog') }}
        </button></template
      ></BaseDialog
    >
  </section>
</template>
<style scoped>
.agent-form,
.agent-credentials {
  display: grid;
  gap: 1rem;
}
.agent-credentials {
  margin-top: 1.5rem;
}
.agent-token-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
}
.agent-token-row code {
  overflow-wrap: anywhere;
  min-width: 0;
}
.agent-card {
  display: grid;
  gap: 1rem;
}
.agent-card .project-team-status {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 1rem;
  font-size: 0.875rem;
  color: var(--muted);
}
.agent-card .team-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}
.agent-settings .page-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
}
.agent-form .checkbox-field {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}
.agent-form .checkbox-field input[type='checkbox'] {
  width: 1.15rem;
  height: 1.15rem;
  min-height: 0;
  margin: 0;
  flex: 0 0 auto;
}
.status-pill {
  display: inline-flex;
  align-items: center;
  border: 1px solid var(--border);
  border-radius: 999px;
  padding: 0.15rem 0.5rem;
  font-size: 0.75rem;
  color: var(--text);
}

.agent-secret {
  width: 100%;
  min-height: 6rem;
  overflow-wrap: anywhere;
}
.agent-avatar {
  display: grid;
  place-items: center;
  flex-shrink: 0;
}
.agent-settings .page-heading {
  flex-wrap: wrap;
  gap: 1rem;
}
</style>
