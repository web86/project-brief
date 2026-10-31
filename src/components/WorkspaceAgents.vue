<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { agentIcon, agentCredentials } from '../services/agentPresentation.js'
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
const projectSearch = ref(''),
  projectOpen = ref(true),
  guideOpen = ref(false),
  baseline = ref('')
const projectChoices = computed(() =>
  (state.value?.projects || []).filter((project) =>
    project.name.toLocaleLowerCase().includes(projectSearch.value.trim().toLocaleLowerCase()),
  ),
)
const selectedProjects = computed(() =>
  (state.value?.projects || []).filter((project) => draft.value.projectIds?.includes(project.id)),
)
const fingerprint = () =>
  JSON.stringify({ ...draft.value, projectIds: [...(draft.value.projectIds || [])].sort() })
function closeEditor() {
  if (
    !busy.value &&
    (fingerprint() === baseline.value || window.confirm(t('agents.discardChanges')))
  )
    open.value = false
}
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
    editing.value = null
    guideOpen.value = false
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
  projectSearch.value = ''
  projectOpen.value = !agent
  baseline.value = fingerprint()
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
    action('/' + agent.id, 'DELETE', undefined, true)
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
          ><AppIcon :name="agentIcon(agent.kind)" :size="24"
        /></span>
        <div>
          <strong>{{ agent.name }}</strong>
          <p class="small muted">{{ agent.description || t('agents.kind.' + agent.kind) }}</p>
        </div>
      </div>
      <div class="agent-card-tags">
        <span>{{ t('agents.kind.' + agent.kind) }}</span
        ><span class="status-pill">{{ t(agent.active ? 'agents.active' : 'agents.disabled') }}</span
        ><span>{{ t('agents.allowedCount', { count: agent.projectIds.length }) }}</span>
      </div>
      <dl class="agent-metrics">
        <div>
          <dt>{{ t('agents.lastActivity') }}</dt>
          <dd v-if="agent.lastActivityAt">{{ formatDate(agent.lastActivityAt) }}</dd>
          <dd v-else>
            {{
              t(
                agent.successfulRequests || agent.failedRequests
                  ? 'agents.noSuccessfulActivity'
                  : 'agents.noActivity',
              )
            }}
          </dd>
        </div>
        <div>
          <dt>{{ t('agents.apiRequests') }}</dt>
          <dd>
            {{
              t('agents.requests', {
                success: agent.successfulRequests,
                failed: agent.failedRequests,
              })
            }}
          </dd>
        </div>
        <div v-if="agentCredentials(agent)">
          <dt>{{ t('agents.credentials') }}</dt>
          <dd>{{ t('agents.credentialState.' + agentCredentials(agent)) }}</dd>
        </div>
        <div v-if="agent.webhook">
          <dt>Webhook</dt>
          <dd>
            {{
              agent.webhook.enabled
                ? agent.webhook.status
                  ? t('agents.delivery.' + agent.webhook.status)
                  : t('agents.delivery.pending')
                : t('agents.disabled')
            }}<span v-if="agent.webhook.lastError"> · {{ agent.webhook.lastError }}</span>
          </dd>
        </div>
      </dl>
      <div class="team-actions">
        <button v-if="!agent.lastActivityAt" class="text-button" @click="guideOpen = true">
          {{ t('agents.howTo') }}
        </button>
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
      class="management-dialog agent-editor-dialog"
      @close="closeEditor"
    >
      <div class="management-body agent-editor-body">
        <form
          id="agent-editor"
          class="agent-form"
          @submit.prevent="
            action(editing ? '/' + editing.id : '', editing ? 'PATCH' : 'POST', draft, true)
          "
        >
          <p v-if="error" class="field-error" role="alert">{{ error }}</p>
          <div class="agent-primary-fields">
            <label class="field" for="agent-name"
              >{{ t('agents.name')
              }}<input
                id="agent-name"
                v-model="draft.name"
                required
                maxlength="100"
                :disabled="busy"
            /></label>
            <label class="field" for="agent-kind"
              >{{ t('agents.type')
              }}<select
                id="agent-kind"
                v-model="draft.kind"
                :disabled="busy"
                aria-describedby="agent-kind-hint"
              >
                <option v-for="kind in ['ai', 'automation', 'service']" :key="kind" :value="kind">
                  {{ t('agents.kind.' + kind) }}
                </option>
              </select></label
            >
          </div>
          <p id="agent-kind-hint" class="small muted">
            {{ t('agents.kindHint.' + draft.kind) }} {{ t('agents.sameApi') }}
          </p>
          <label class="field" for="agent-description"
            >{{ t('agents.description')
            }}<textarea
              id="agent-description"
              v-model="draft.description"
              rows="2"
              maxlength="1000"
              :disabled="busy"
            />
          </label>
          <section class="agent-project-picker">
            <button
              type="button"
              class="agent-picker-toggle"
              :aria-expanded="projectOpen"
              aria-controls="agent-project-options"
              @click="projectOpen = !projectOpen"
            >
              {{ t('agents.allowedProjects') }}
              <span class="status-pill">{{
                t('agents.selectedCount', { count: draft.projectIds?.length || 0 })
              }}</span
              ><AppIcon name="chevron" :size="16" />
            </button>
            <div v-if="selectedProjects.length" class="agent-project-chips">
              <button
                v-for="project in selectedProjects"
                :key="project.id"
                type="button"
                :disabled="busy"
                :aria-label="t('agents.removeProject', { name: project.name })"
                @click="draft.projectIds = draft.projectIds.filter((id) => id !== project.id)"
              >
                <span>{{ project.name }}</span
                ><AppIcon name="close" :size="12" /></button
              ><button
                type="button"
                class="text-button"
                :disabled="busy"
                @click="draft.projectIds = []"
              >
                {{ t('agents.clearProjects') }}
              </button>
            </div>
            <div v-show="projectOpen" id="agent-project-options">
              <label class="field" for="agent-project-search"
                >{{ t('agents.projectSearch')
                }}<input
                  id="agent-project-search"
                  v-model="projectSearch"
                  type="search"
                  :disabled="busy"
              /></label>
              <fieldset class="project-choices agent-project-options" :disabled="busy">
                <legend class="sr-only">{{ t('agents.allowedProjects') }}</legend>
                <label v-for="project in projectChoices" :key="project.id"
                  ><input v-model="draft.projectIds" type="checkbox" :value="project.id" /><span>{{
                    project.name
                  }}</span></label
                >
                <p v-if="!projectChoices.length" class="small muted">
                  {{ t('agents.noProjectsFound') }}
                </p>
              </fieldset>
            </div>
            <p class="small muted">{{ t('agents.grantHint') }}</p>
          </section>
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
      </div>
      <template #footer
        ><button
          v-if="editing"
          class="text-button danger management-delete"
          :disabled="busy"
          @click="remove(editing)"
        >
          {{ t('ui.delete') }}</button
        ><button class="button secondary" :disabled="busy" @click="closeEditor">
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
      :open="guideOpen"
      :title="t('agents.howTo')"
      id="agent-guide-title"
      @close="guideOpen = false"
      ><ol class="agent-connect-steps">
        <li v-for="step in ['token', 'run', 'team', 'task', 'report']" :key="step">
          {{ t('agents.connect.' + step) }}
        </li>
      </ol>
      <p class="small muted">{{ t('agents.guideDocs') }}</p>
      <template #footer
        ><button class="button primary" @click="guideOpen = false">
          {{ t('ui.closeDialog') }}
        </button></template
      ></BaseDialog
    >
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
.agent-editor-dialog {
  width: min(880px, calc(100% - 24px));
  max-height: 90dvh;
}
.agent-editor-dialog :deep(.modal-content) {
  max-height: 90dvh;
}
.agent-editor-body {
  gap: 14px;
}

.agent-primary-fields {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(220px, 0.8fr);
  gap: 16px;
}
.agent-primary-fields .field,
.agent-form .field {
  margin: 0;
  min-width: 0;
}
.agent-form textarea {
  min-height: 64px;
}
.agent-project-picker {
  border: 1px solid var(--border);
  border-radius: 9px;
  padding: 12px;
  min-width: 0;
}
.agent-picker-toggle {
  display: flex;
  gap: 8px;
  align-items: center;
  width: 100%;
  text-align: start;
  background: none;
  color: var(--text);
  border: 0;
  padding: 4px 0;
  cursor: pointer;
  font-weight: 600;
}
.agent-picker-toggle svg {
  margin-inline-start: auto;
}
.agent-project-chips {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin: 8px 0;
}
.agent-project-chips button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 4px 8px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--surface-muted);
  color: var(--text);
  font-size: 0.8rem;
  min-height: 32px;
}
.agent-project-chips span {
  overflow-wrap: anywhere;
  min-width: 0;
}
.agent-project-options {
  max-height: 220px;
  overflow-y: auto;
  margin: 8px 0;
  padding: 4px;
  border: 0;
}
.agent-project-options label {
  min-height: 36px;
  padding: 4px;
  overflow-wrap: anywhere;
}
.agent-card-tags {
  display: flex;
  gap: 8px 14px;
  flex-wrap: wrap;
  color: var(--muted);
  font-size: 0.85rem;
}
.agent-metrics {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 24px;
  margin: 0;
}
.agent-metrics dt {
  color: var(--muted);
  font-size: 0.75rem;
  margin-bottom: 4px;
}
.agent-metrics dd {
  margin: 0;
  font-size: 0.85rem;
  overflow-wrap: anywhere;
}
.agent-connect-steps {
  padding-inline-start: 24px;
  display: grid;
  gap: 10px;
}
@media (max-width: 600px) {
  .agent-primary-fields,
  .agent-metrics {
    grid-template-columns: 1fr;
  }
  .agent-editor-dialog :deep(.modal-header),
  .agent-editor-dialog :deep(.modal-footer),
  .agent-editor-body {
    padding: 14px 16px;
  }
}

.agent-form,
.agent-credentials {
  display: grid;
  gap: 12px;
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
