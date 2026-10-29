<script setup>
import { isSecurityCancellation } from '../services/stepUp.js'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { api } from '../api/client.js'
import { toast } from '../services/toast.js'
import { t, formatApiError, intlLocale } from '../i18n/index.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import LoadingButton from '../components/LoadingButton.vue'
const nav = useAdminNavigationStore()
const allowed = computed(() => !!nav.workspace && nav.user?.membershipRole === 'owner')
const state = ref(null),
  tab = ref('tokens'),
  busy = ref(false),
  loading = ref(true),
  validation = ref(null),
  loadFailed = ref(false),
  historyBusy = ref(false),
  copying = ref(false)
const sourceId = ref(''),
  sourceName = ref('')
const invitationMode = ref('immediate')
const invitationExample = computed(() =>
  JSON.stringify({ sendInvitation: invitationMode.value === 'immediate' }, null, 2),
)
const name = ref(''),
  scopes = ref(['projects:read', 'tasks:read']),
  expires = ref('')
const hookName = ref(''),
  url = ref(''),
  events = ref(['task.approved']),
  editing = ref(null)
const projectFilter = ref('all'),
  projectIds = ref([]),
  projectSearch = ref('')
const visibleProjects = computed(() =>
  (state.value?.projects || []).filter((project) =>
    project.name
      .toLocaleLowerCase(intlLocale())
      .includes(projectSearch.value.trim().toLocaleLowerCase(intlLocale())),
  ),
)
const validProjectIds = computed(() =>
  projectIds.value.filter((id) => state.value?.projects?.some((project) => project.id === id)),
)
const projectNames = (hook) =>
  hook.projectIds
    ?.map((id) => state.value?.projects?.find((project) => project.id === id)?.name)
    .filter(Boolean) || []
const secret = ref(''),
  secretDialog = ref(null),
  copied = ref(false)
const operations = ref(null),
  operationPage = ref(1)
const deliveries = ref(null),
  selected = ref(null),
  page = ref(1)
let revision = 0,
  listRevision = 0,
  dataRevision = 0,
  historyRevision = 0
const revokedIds = new Set(),
  deletedIds = new Set()
const root = '/api/admin/integrations'
const validationMessage = computed(() =>
  validation.value?.errors?.projectIds || validation.value?.errors?.['projectIds.0']
    ? t('integrations.selectProjectRequired')
    : validation.value?.errors?.url
      ? t('integrations.urlError')
      : formatApiError(validation.value),
)
function selectTab(value) {
  tab.value = value
  validation.value = null
  if (value === 'operations') void loadOperations()
}
const scopeLabel = (s) => t('integrations.scope_' + s.replace(':', '_'))
const eventLabel = (s) => t('integrations.event_' + s.replaceAll('.', '_'))
function date(value) {
  if (!value) return t('integrations.never')
  const iso = value.includes('T') ? value : value.replace(' ', 'T') + 'Z'
  return new Intl.DateTimeFormat(intlLocale(), { dateStyle: 'medium', timeStyle: 'short' }).format(
    new Date(iso),
  )
}
function tokenStatus(token) {
  return token.revokedAt
    ? 'revoked'
    : token.expiresAt &&
        new Date(token.expiresAt.replace(' ', 'T') + (token.expiresAt.includes('T') ? '' : 'Z')) <=
          new Date()
      ? 'expired'
      : 'active'
}
const activeTokens = computed(() =>
  (state.value?.tokens || []).filter(
    (token) => !revokedIds.has(token.id) && tokenStatus(token) === 'active',
  ),
)
function reportError(cause, current = revision) {
  if (isSecurityCancellation(cause)) return
  if (current !== revision) return
  validation.value =
    cause.status === 422 || cause.messageKey === 'integrations.selectProjectRequired' ? cause : null
  toast.error(() => formatApiError(cause))
}
async function load(current = revision) {
  const attempt = ++listRevision,
    version = dataRevision
  const result = await api.request(root)
  if (current !== revision || attempt !== listRevision || version !== dataRevision) return false
  state.value = {
    ...result,
    tokens: result.tokens.filter((token) => !revokedIds.has(token.id)),
    webhooks: result.webhooks.filter((hook) => !deletedIds.has(hook.id)),
  }
  loadFailed.value = false
  return true
}
async function retryLoad() {
  if (loading.value || busy.value) return
  const current = revision
  validation.value = null
  loading.value = true
  try {
    await load(current)
  } catch (cause) {
    if (isSecurityCancellation(cause)) return
    if (current === revision) loadFailed.value = true
    reportError(cause, current)
  } finally {
    if (current === revision) loading.value = false
  }
}
function hideSecret() {
  secret.value = ''
  copied.value = false
  secretDialog.value?.close()
}
async function showSecret(value) {
  secret.value = value
  copied.value = false
  await nextTick()
  secretDialog.value?.showModal()
}
async function copy() {
  if (copying.value || !secret.value) return
  const current = revision,
    value = secret.value
  copying.value = true
  try {
    await navigator.clipboard.writeText(value)
    if (current !== revision || value !== secret.value) return
    if (!copied.value) toast.success(() => t('integrations.copied'))
    copied.value = true
  } catch {
    reportError({ messageKey: 'ui.weCouldNotCompleteThisActionPleaseTry' }, current)
  } finally {
    if (current === revision) copying.value = false
  }
}
watch(
  () => nav.workspace?.id,
  async () => {
    const current = ++revision
    hideSecret()
    state.value = null
    invitationMode.value = 'immediate'
    sourceId.value = ''
    sourceName.value = ''
    deliveries.value = null
    operations.value = null
    operationPage.value = 1
    selected.value = null
    resetHook()
    validation.value = null
    loadFailed.value = false
    busy.value = false
    historyBusy.value = false
    copying.value = false
    revokedIds.clear()
    deletedIds.clear()
    dataRevision++
    listRevision++
    historyRevision++
    loading.value = true
    try {
      if (allowed.value) {
        await load(current)
        if (tab.value === 'operations') await loadOperations(1, current)
      }
    } catch (cause) {
      if (isSecurityCancellation(cause)) return
      if (current === revision) loadFailed.value = true
      reportError(cause, current)
    } finally {
      if (current === revision) loading.value = false
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  revision++
  hideSecret()
})
async function action(
  path,
  method,
  body,
  { queued = false, successKey = 'integrations.saved', apply, refresh = true } = {},
) {
  if (busy.value || !allowed.value) return
  const current = revision
  busy.value = true
  validation.value = null
  let result
  try {
    result = await api.request(root + path, { method, body })
    if (current !== revision) return
    dataRevision++
    listRevision++
    apply?.(result)
    if (result.secret) await showSecret(result.secret)
    if (refresh) {
      await load(current)
      if (current !== revision) return
      if (selected.value && !deletedIds.has(selected.value.id))
        await loadHistory(selected.value, page.value, current)
    }
    if (current === revision) {
      if (queued) toast.info(() => t('integrations.queued'))
      else toast.success(() => t(successKey))
      return result
    }
  } catch (cause) {
    if (isSecurityCancellation(cause)) return
    reportError(cause, current)
    if (result && current === revision) loadFailed.value = true
    return current === revision ? result : undefined
  } finally {
    if (current === revision) busy.value = false
  }
}
async function loadOperations(targetPage = 1, current = revision) {
  if (historyBusy.value || !allowed.value) return
  historyBusy.value = true
  const attempt = ++historyRevision
  try {
    const result = await api.request(`${root}/operations?page=${targetPage}`)
    if (current !== revision || attempt !== historyRevision) return
    operations.value = result
    operationPage.value = targetPage
  } catch (cause) {
    reportError(cause, current)
  } finally {
    if (current === revision) historyBusy.value = false
  }
}
function toggleProvisioning(token, event) {
  const provisioningEnabled = event.target.checked
  event.target.checked = token.provisioningEnabled
  return action('/tokens/' + token.id, 'PATCH', { provisioningEnabled })
}
async function revoke(token) {
  if (busy.value || !window.confirm(t('integrations.confirmRevoke'))) return
  return action('/tokens/' + token.id, 'DELETE', undefined, {
    refresh: false,
    successKey: 'integrations.tokenRevoked',
    apply() {
      revokedIds.add(token.id)
      state.value.tokens = state.value.tokens.filter((item) => item.id !== token.id)
    },
  })
}
async function deleteHook(hook) {
  if (busy.value || !window.confirm(t('integrations.confirmDelete', { name: hook.name }))) return
  return action('/webhooks/' + hook.id, 'DELETE', undefined, {
    refresh: false,
    successKey: 'integrations.webhookDeleted',
    apply() {
      deletedIds.add(hook.id)
      state.value.webhooks = state.value.webhooks.filter((item) => item.id !== hook.id)
      if (selected.value?.id === hook.id) {
        historyRevision++
        selected.value = null
        deliveries.value = null
      }
      if (editing.value === hook.id) resetHook()
    },
  })
}
function toggleHook(hook, event) {
  const enabled = event.target.checked
  event.target.checked = hook.enabled
  return action(
    '/webhooks/' + hook.id,
    'PATCH',
    { enabled },
    {
      apply() {
        hook.enabled = enabled
      },
    },
  )
}
async function createToken() {
  const result = await action('/tokens', 'POST', {
    name: name.value,
    scopes: scopes.value,
    expiresAt: expires.value ? expires.value + 'T23:59:59Z' : null,
    ...(scopes.value.includes('requests:create')
      ? sourceId.value
        ? { sourceId: sourceId.value }
        : { sourceName: sourceName.value }
      : {}),
  })
  if (result) {
    name.value = ''
    expires.value = ''
  }
}
function edit(hook) {
  editing.value = hook.id
  hookName.value = hook.name
  url.value = hook.url
  events.value = [...hook.events]
  projectFilter.value = hook.projectFilter || 'all'
  projectIds.value = [...(hook.projectIds || [])]
  projectSearch.value = ''
}
function resetHook() {
  editing.value = null
  hookName.value = ''
  url.value = ''
  events.value = ['task.approved']
  projectFilter.value = 'all'
  projectIds.value = []
  projectSearch.value = ''
}
async function saveHook() {
  if (projectFilter.value === 'selected' && !validProjectIds.value.length) {
    reportError({ messageKey: 'integrations.selectProjectRequired' })
    return
  }
  const result = await action(
    '/webhooks' + (editing.value ? '/' + editing.value : ''),
    editing.value ? 'PATCH' : 'POST',
    {
      name: hookName.value,
      url: url.value,
      events: events.value,
      projectFilter: projectFilter.value,
      projectIds: projectFilter.value === 'selected' ? validProjectIds.value : [],
    },
  )
  if (result) resetHook()
}
async function loadHistory(hook, targetPage = 1, current = revision) {
  const attempt = ++historyRevision
  const result = await api.request(`${root}/webhooks/${hook.id}/deliveries?page=${targetPage}`)
  if (current !== revision || attempt !== historyRevision || deletedIds.has(hook.id)) return
  selected.value = hook
  deliveries.value = result
  page.value = targetPage
}
async function history(hook, targetPage = 1) {
  if (historyBusy.value) return
  const current = revision
  historyBusy.value = true
  try {
    await loadHistory(hook, targetPage, current)
  } catch (cause) {
    if (isSecurityCancellation(cause)) return
    if (!deletedIds.has(hook.id)) reportError(cause, current)
  } finally {
    if (current === revision) historyBusy.value = false
  }
}
async function confirmAction(key, path, method = 'POST') {
  if (!busy.value && window.confirm(t('integrations.' + key))) return action(path, method)
}
</script>
<template>
  <div class="integrations-page">
    <div class="page-heading">
      <div>
        <h1>{{ t('integrations.title') }}</h1>
        <p class="muted">{{ t('integrations.hint') }}</p>
      </div>
    </div>
    <p v-if="!allowed" role="alert">{{ t('integrations.readOnly') }}</p>
    <template v-else>
      <button
        v-if="loadFailed && state"
        class="button secondary"
        :disabled="busy || loading"
        @click="retryLoad"
      >
        {{ t('ui.tryAgain') }}
      </button>
      <div class="integration-tabs" role="tablist" :aria-label="t('integrations.title')">
        <button
          v-for="item in ['tokens', 'webhooks', 'operations']"
          :id="'integration-tab-' + item"
          :key="item"
          role="tab"
          :aria-selected="tab === item"
          :aria-controls="'integration-panel-' + item"
          class="button"
          :class="tab === item ? 'primary' : 'secondary'"
          @click="selectTab(item)"
        >
          {{ t(item === 'operations' ? 'provisioning.operations' : 'integrations.' + item) }}
        </button>
      </div>
      <p v-if="loading" role="status">{{ t('nav.loading') }}</p>
      <button v-else-if="!state" class="button secondary" @click="retryLoad">
        {{ t('ui.tryAgain') }}
      </button>
      <template v-else>
        <section
          v-show="tab === 'tokens'"
          id="integration-panel-tokens"
          role="tabpanel"
          aria-labelledby="integration-tab-tokens"
        >
          <form class="surface integration-form" @submit.prevent="createToken">
            <h2>{{ t('integrations.create') }} · {{ t('integrations.tokens') }}</h2>
            <p v-if="validation && tab === 'tokens'" class="field-error" role="alert">
              {{ validationMessage }}
            </p>
            <label class="field" for="token-name"
              >{{ t('integrations.name')
              }}<input id="token-name" v-model="name" maxlength="100" required :disabled="busy"
            /></label>
            <fieldset class="integration-choices">
              <legend>{{ t('integrations.scopes') }}</legend>
              <label v-for="scope in state.scopes" :key="scope"
                ><input v-model="scopes" type="checkbox" :value="scope" :disabled="busy" />{{
                  scopeLabel(scope)
                }}
                <code>{{ scope }}</code></label
              >
            </fieldset>
            <p
              v-if="scopes.includes('comments:read') || scopes.includes('attachments:read')"
              class="small muted"
              role="note"
            >
              {{ t('integrations.sensitiveReadHint') }}
            </p>
            <p v-if="scopes.includes('projects:provision')" class="small muted" role="note">
              {{ t('provisioning.scopeHint') }}
            </p>
            <div v-if="scopes.includes('requests:create')" class="request-source-controls">
              <label class="field" for="token-source"
                >{{ t('requests.sourceLabel')
                }}<select id="token-source" v-model="sourceId" :disabled="busy">
                  <option value="">{{ t('requests.newSource') }}</option>
                  <option v-for="source in state.sources || []" :key="source.id" :value="source.id">
                    {{ source.name }}
                  </option>
                </select></label
              >
              <label v-if="!sourceId" class="field" for="token-source-name"
                >{{ t('requests.sourceName')
                }}<input
                  id="token-source-name"
                  v-model="sourceName"
                  maxlength="100"
                  required
                  :disabled="busy"
              /></label>
              <p class="small muted">{{ t('requests.sourceHint') }}</p>
            </div>
            <p v-if="scopes.includes('requests:approve')" class="small muted">
              {{ t('requests.approveHint') }}
            </p>
            <label class="field" for="token-expires"
              >{{ t('integrations.expires')
              }}<input id="token-expires" v-model="expires" type="date" :disabled="busy"
            /></label>
            <LoadingButton
              type="submit"
              class="button primary"
              :busy="busy"
              :disabled="busy || !scopes.length"
              >{{ t('integrations.create') }}</LoadingButton
            >
          </form>
          <section
            class="surface integration-form provisioning-invitation-mode"
            aria-labelledby="invitation-mode-title"
          >
            <h2 id="invitation-mode-title">{{ t('provisioning.invitationModeTitle') }}</h2>
            <fieldset class="integration-choices">
              <legend>{{ t('provisioning.invitationModeLabel') }}</legend>
              <label
                ><input
                  v-model="invitationMode"
                  type="radio"
                  name="invitation-mode"
                  value="immediate"
                />{{ t('provisioning.invitationImmediate') }}</label
              >
              <label
                ><input
                  v-model="invitationMode"
                  type="radio"
                  name="invitation-mode"
                  value="self_service"
                />{{ t('provisioning.invitationSelfService') }}</label
              >
            </fieldset>
            <p class="small muted">{{ t('provisioning.invitationModeHint') }}</p>
            <pre
              class="provisioning-request-example"
              aria-live="polite"
            ><code>{{ invitationExample }}</code></pre>
          </section>
          <section class="surface integration-list">
            <h2>{{ t('integrations.tokens') }}</h2>
            <p v-if="!activeTokens.length" class="muted">{{ t('integrations.empty') }}</p>
            <article v-for="token in activeTokens" :key="token.id" class="integration-item">
              <div>
                <h3>{{ token.name }}</h3>
                <label
                  v-if="token.scopes.includes('projects:provision')"
                  class="integration-toggle"
                >
                  <input
                    type="checkbox"
                    :checked="token.provisioningEnabled"
                    :disabled="busy"
                    @change="toggleProvisioning(token, $event)"
                  />
                  {{ t('provisioning.enabled') }}
                </label>
                <p class="small">{{ token.scopes.map(scopeLabel).join(' · ') }}</p>
                <p class="small muted">
                  {{ t('integrations.created') }}: {{ date(token.createdAt) }} ·
                  {{ t('integrations.expires') }}: {{ date(token.expiresAt) }}
                </p>
                <p class="small muted">
                  {{ t('integrations.lastUsed') }}: {{ date(token.lastUsedAt) }} ·
                  {{ t('integrations.' + tokenStatus(token)) }}
                </p>
              </div>
              <button
                v-if="!token.revokedAt"
                class="button secondary"
                :disabled="busy"
                @click="revoke(token)"
              >
                {{ t('integrations.revoke') }}
              </button>
            </article>
          </section>
        </section>
        <section
          v-show="tab === 'operations'"
          id="integration-panel-operations"
          role="tabpanel"
          aria-labelledby="integration-tab-operations"
          class="surface integration-list"
        >
          <h2>{{ t('provisioning.operations') }}</h2>
          <p class="muted small">{{ t('provisioning.operationsHint') }}</p>
          <button
            class="button secondary"
            :disabled="historyBusy"
            @click="loadOperations(operationPage)"
          >
            {{ t('provisioning.refresh') }}
          </button>
          <p v-if="historyBusy" role="status">{{ t('nav.loading') }}</p>
          <p v-else-if="!operations?.data.length" class="muted">{{ t('integrations.empty') }}</p>
          <article
            v-for="operation in operations?.data || []"
            :key="operation.id"
            class="integration-item"
          >
            <div>
              <h3>{{ operation.tokenName }}</h3>
              <p>
                {{
                  t(
                    'requests.operation_' +
                      (operation.operationType || 'project.provision').replace('.', '_'),
                  )
                }}
              </p>
              <p>{{ t('provisioning.' + operation.outcome) }} · HTTP {{ operation.httpStatus }}</p>
              <p v-if="operation.invitation" class="small">
                {{ t('provisioning.invitation') }}:
                {{ t('provisioning.status_' + operation.invitation) }}
              </p>
              <p class="small muted">{{ date(operation.createdAt) }}</p>
            </div>
          </article>
          <div v-if="operations && operations.meta.lastPage > 1" class="integration-tabs">
            <button
              class="button secondary"
              :disabled="historyBusy || operationPage <= 1"
              @click="loadOperations(operationPage - 1)"
            >
              {{ t('integrations.previous') }}
            </button>
            <span>{{ operationPage }} / {{ operations.meta.lastPage }}</span>
            <button
              class="button secondary"
              :disabled="historyBusy || operationPage >= operations.meta.lastPage"
              @click="loadOperations(operationPage + 1)"
            >
              {{ t('integrations.next') }}
            </button>
          </div>
        </section>
        <section
          v-show="tab === 'webhooks'"
          id="integration-panel-webhooks"
          role="tabpanel"
          aria-labelledby="integration-tab-webhooks"
        >
          <p class="muted small">{{ t('integrations.queueHint') }}</p>
          <form class="surface integration-form" @submit.prevent="saveHook">
            <h2>{{ t(editing ? 'integrations.edit' : 'integrations.create') }} · Webhook</h2>
            <p v-if="validation && tab === 'webhooks'" class="field-error" role="alert">
              {{ validationMessage }}
            </p>
            <label class="field" for="hook-name"
              >{{ t('integrations.name')
              }}<input id="hook-name" v-model="hookName" maxlength="100" required :disabled="busy"
            /></label>
            <label class="field" for="hook-url"
              >{{ t('integrations.url')
              }}<input
                id="hook-url"
                v-model="url"
                type="url"
                placeholder="https://crm.example.com/webhook"
                maxlength="2048"
                required
                :disabled="busy"
            /></label>
            <fieldset class="integration-choices integration-project-filter">
              <legend>{{ t('integrations.projectFilter') }}</legend>
              <label
                ><input
                  v-model="projectFilter"
                  type="radio"
                  name="project-filter"
                  value="all"
                  :disabled="busy"
                />{{ t('integrations.allProjects') }}</label
              >
              <label
                ><input
                  v-model="projectFilter"
                  type="radio"
                  name="project-filter"
                  value="selected"
                  :disabled="busy"
                />{{ t('integrations.selectedProjects') }}</label
              >
              <template v-if="projectFilter === 'selected'">
                <label class="field" for="hook-project-search"
                  >{{ t('integrations.searchProjects')
                  }}<input
                    id="hook-project-search"
                    v-model="projectSearch"
                    type="search"
                    :disabled="busy"
                /></label>
                <p role="status" class="small muted">
                  {{ t('integrations.selectedCount', { count: validProjectIds.length }) }}
                </p>
                <p v-if="!state.projects?.length" class="small muted">
                  {{ t('integrations.noProjects') }}
                </p>
                <p v-else-if="!visibleProjects.length" class="small muted">
                  {{ t('integrations.noProjectMatches') }}
                </p>
                <div class="integration-project-options">
                  <label v-for="project in visibleProjects" :key="project.id"
                    ><input
                      v-model="projectIds"
                      type="checkbox"
                      :value="project.id"
                      :disabled="busy"
                    /><span>{{ project.name }}</span></label
                  >
                </div>
                <p v-if="!validProjectIds.length" class="field-error">
                  {{ t('integrations.selectProjectRequired') }}
                </p>
              </template>
              <p class="small muted">{{ t('integrations.filterHint') }}</p>
            </fieldset>
            <fieldset class="integration-choices">
              <legend>{{ t('integrations.events') }}</legend>
              <label v-for="event in state.events" :key="event"
                ><input v-model="events" type="checkbox" :value="event" :disabled="busy" />{{
                  eventLabel(event)
                }}
                <code>{{ event }}</code></label
              >
            </fieldset>
            <div class="integration-actions">
              <LoadingButton
                type="submit"
                class="button primary"
                :busy="busy"
                :disabled="
                  busy ||
                  !events.length ||
                  (projectFilter === 'selected' && !validProjectIds.length)
                "
                >{{ t(editing ? 'integrations.save' : 'integrations.create') }}</LoadingButton
              ><button
                v-if="editing"
                type="button"
                class="button secondary"
                :disabled="busy"
                @click="resetHook"
              >
                {{ t('integrations.cancel') }}
              </button>
            </div>
          </form>
          <section class="surface integration-list">
            <h2>{{ t('integrations.webhooks') }}</h2>
            <p v-if="!state.webhooks.length" class="muted">{{ t('integrations.empty') }}</p>
            <article
              v-for="hook in state.webhooks"
              :key="hook.id"
              class="integration-item webhook-item"
            >
              <h3>{{ hook.name }}</h3>
              <p class="integration-url">{{ hook.url }}</p>
              <p class="small muted">{{ hook.events.map(eventLabel).join(' · ') }}</p>
              <p class="small integration-project-summary">
                {{
                  hook.projectFilter === 'selected'
                    ? t('integrations.selectedProjects') +
                      ': ' +
                      (projectNames(hook).join(' · ') || t('integrations.filterEmpty'))
                    : t('integrations.allProjects')
                }}
              </p>
              <label class="integration-toggle"
                ><input
                  type="checkbox"
                  :checked="hook.enabled"
                  :disabled="busy"
                  @change="toggleHook(hook, $event)"
                />{{ t('integrations.enabled') }}</label
              >
              <div class="integration-actions">
                <button class="button secondary" :disabled="busy" @click="edit(hook)">
                  {{ t('integrations.edit') }}</button
                ><button
                  class="button secondary"
                  :disabled="busy || !hook.enabled"
                  @click="
                    action('/webhooks/' + hook.id + '/test', 'POST', undefined, { queued: true })
                  "
                >
                  {{ t('integrations.test') }}</button
                ><button
                  class="button secondary"
                  :disabled="busy || historyBusy"
                  @click="history(hook)"
                >
                  {{ t('integrations.history') }}</button
                ><button
                  class="text-button"
                  :disabled="busy"
                  @click="confirmAction('confirmRotate', '/webhooks/' + hook.id + '/rotate')"
                >
                  {{ t('integrations.rotate') }}</button
                ><button class="text-button danger" :disabled="busy" @click="deleteHook(hook)">
                  {{ t('integrations.delete') }}
                </button>
              </div>
            </article>
          </section>
          <section v-if="deliveries && selected" class="surface integration-list">
            <div class="integration-actions">
              <h2>{{ t('integrations.history') }} · {{ selected.name }}</h2>
              <button
                class="button secondary"
                :disabled="busy || historyBusy"
                @click="history(selected, page)"
              >
                {{ t('integrations.refresh') }}
              </button>
            </div>
            <p v-if="!deliveries.data.length" class="muted">{{ t('integrations.noHistory') }}</p>
            <article
              v-for="delivery in deliveries.data"
              :key="delivery.id"
              class="integration-item webhook-item"
            >
              <h3>{{ eventLabel(delivery.type) }}</h3>
              <p>
                {{ t('integrations.status_' + delivery.status) }} ·
                {{ t('integrations.attempts') }}: {{ delivery.attempts }}
                <span v-if="delivery.httpStatus">· HTTP {{ delivery.httpStatus }}</span>
              </p>
              <p v-if="delivery.errorCode" class="field-error">
                {{ t('integrations.error_' + delivery.errorCode) }}
              </p>
              <p class="small muted">{{ date(delivery.createdAt) }}</p>
              <code class="integration-id">{{ delivery.eventId }}</code>
              <ul v-if="delivery.history.length" class="small">
                <li v-for="(attempt, index) in delivery.history" :key="index">
                  {{ date(attempt.created_at) }} ·
                  {{ t('integrations.status_' + attempt.status) }} ·
                  {{ attempt.http_status || '—' }} · {{ attempt.duration_ms }} ms
                </li>
              </ul>
              <button
                v-if="delivery.canRetry"
                class="button secondary"
                :disabled="busy"
                @click="
                  action(
                    '/webhooks/' + selected.id + '/deliveries/' + delivery.id + '/retry',
                    'POST',
                    undefined,
                    { queued: true },
                  )
                "
              >
                {{ t('integrations.retry') }}
              </button>
            </article>
            <div class="integration-actions">
              <button
                class="button secondary"
                :disabled="page <= 1 || busy || historyBusy"
                @click="history(selected, page - 1)"
              >
                {{ t('integrations.previous') }}</button
              ><span>{{ page }} / {{ deliveries.meta.lastPage }}</span
              ><button
                class="button secondary"
                :disabled="page >= deliveries.meta.lastPage || busy || historyBusy"
                @click="history(selected, page + 1)"
              >
                {{ t('integrations.next') }}
              </button>
            </div>
          </section>
        </section>
      </template>
    </template>
    <dialog
      ref="secretDialog"
      class="integration-secret surface"
      aria-labelledby="secret-title"
      @close="hideSecret"
      @cancel.prevent="hideSecret"
      @click="$event.target === secretDialog && hideSecret()"
    >
      <h2 id="secret-title">{{ t('integrations.once') }}</h2>
      <p>{{ t('integrations.onceHint') }}</p>
      <code v-if="secret" class="integration-secret-value">{{ secret }}</code>
      <div class="integration-actions">
        <button class="button primary" :disabled="copying" @click="copy">
          {{ t(copied ? 'integrations.copied' : 'integrations.copy') }}</button
        ><button class="button secondary" @click="hideSecret">{{ t('integrations.close') }}</button>
      </div>
    </dialog>
  </div>
</template>
<style scoped>
.integrations-page {
  max-width: 960px;
  min-width: 0;
}
.integration-tabs,
.integration-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin: 16px 0;
}
.integration-form,
.integration-list {
  padding: 24px;
  margin: 20px 0;
}
.integration-form {
  display: grid;
  gap: 18px;
}
.integration-form h2,
.integration-list h2 {
  margin: 0;
  font-size: 1.2rem;
}
.integration-choices {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 16px;
  min-width: 0;
  display: grid;
  gap: 12px;
}
.integration-choices label {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  font-weight: 400;
}
.integration-choices input,
.integration-toggle input {
  width: auto;
}
.integration-choices code {
  color: var(--text-subtle);
  font-size: 0.75rem;
  overflow-wrap: anywhere;
}
.integration-item {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 20px 0;
  border-bottom: 1px solid var(--border);
}
.integration-item:last-child {
  border: 0;
  padding-bottom: 0;
}
.integration-item h3,
.integration-item p {
  margin: 0 0 8px;
  overflow-wrap: anywhere;
}
.webhook-item {
  display: block;
}
.integration-url,
.integration-id {
  overflow-wrap: anywhere;
  word-break: break-word;
}
.provisioning-request-example {
  margin: 0;
  padding: 14px;
  border-radius: 8px;
  background: var(--surface-hover);
  overflow-x: auto;
}
.integration-project-options {
  max-height: 240px;
  overflow-y: auto;
  display: grid;
  gap: 12px;
}
.integration-project-options label {
  flex-wrap: nowrap;
  align-items: flex-start;
}
.integration-project-options span {
  min-width: 0;
  overflow-wrap: anywhere;
}
.integration-project-filter .field {
  display: grid;
}
.integration-project-filter input[type='search'] {
  width: 100%;
  min-width: 0;
}
.integration-toggle {
  display: flex;
  gap: 10px;
  align-items: center;
}
.integration-secret {
  width: min(560px, calc(100vw - 32px));
  max-height: calc(100dvh - 32px);
  overflow: auto;
  padding: 24px;
  color: var(--text);
  border: 1px solid var(--border);
  border-radius: 16px;
}
.integration-secret::backdrop {
  background: #0008;
}
.integration-secret-value {
  display: block;
  overflow-wrap: anywhere;
  user-select: all;
  padding: 16px;
  border: 1px solid var(--border);
  border-radius: 8px;
  margin: 20px 0;
}
@media (max-width: 480px) {
  .integration-form,
  .integration-list {
    padding: 16px;
  }
  .integration-choices {
    padding: 12px;
  }
  .integration-choices label {
    align-items: flex-start;
  }
  .integration-choices code {
    width: 100%;
    padding-left: 24px;
  }
}
</style>
