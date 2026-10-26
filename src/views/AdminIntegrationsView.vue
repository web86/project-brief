<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError, intlLocale } from '../i18n/index.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import LoadingButton from '../components/LoadingButton.vue'
const nav = useAdminNavigationStore()
const allowed = computed(() => !!nav.workspace && nav.user?.membershipRole === 'owner')
const state = ref(null),
  tab = ref('tokens'),
  busy = ref(false),
  loading = ref(true),
  error = ref(''),
  message = ref('')
const name = ref(''),
  scopes = ref(['projects:read', 'tasks:read']),
  expires = ref('')
const hookName = ref(''),
  url = ref(''),
  events = ref(['task.approved']),
  editing = ref(null)
const secret = ref(''),
  secretDialog = ref(null),
  copied = ref(false)
const deliveries = ref(null),
  selected = ref(null),
  page = ref(1)
let revision = 0
const root = '/api/admin/integrations'
const errorMessage = computed(() =>
  error.value?.errors?.url ? t('integrations.urlError') : formatApiError(error.value),
)
function selectTab(value) {
  tab.value = value
  message.value = ''
  error.value = ''
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
async function load(current = revision) {
  const result = await api.request(root)
  if (current === revision) state.value = result
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
  try {
    await navigator.clipboard.writeText(secret.value)
    copied.value = true
  } catch {
    error.value = { messageKey: 'ui.weCouldNotCompleteThisActionPleaseTry' }
  }
}
watch(
  () => nav.workspace?.id,
  async () => {
    const current = ++revision
    hideSecret()
    state.value = null
    deliveries.value = null
    selected.value = null
    editing.value = null
    error.value = ''
    message.value = ''
    loading.value = true
    try {
      if (allowed.value) await load(current)
    } catch (cause) {
      if (current === revision) error.value = cause
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
async function action(path, method, body, queued = false) {
  if (busy.value || !allowed.value) return
  const current = revision
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    const result = await api.request(root + path, { method, body })
    if (current !== revision) return
    if (result.secret) await showSecret(result.secret)
    await load(current)
    if (selected.value) await history(selected.value, page.value)
    message.value = queued ? 'integrations.queued' : 'integrations.saved'
    return result
  } catch (cause) {
    if (current === revision) error.value = cause
  } finally {
    if (current === revision) busy.value = false
  }
}
async function createToken() {
  const result = await action('/tokens', 'POST', {
    name: name.value,
    scopes: scopes.value,
    expiresAt: expires.value ? expires.value + 'T23:59:59Z' : null,
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
}
function resetHook() {
  editing.value = null
  hookName.value = ''
  url.value = ''
  events.value = ['task.approved']
}
async function saveHook() {
  const result = await action(
    '/webhooks' + (editing.value ? '/' + editing.value : ''),
    editing.value ? 'PATCH' : 'POST',
    { name: hookName.value, url: url.value, events: events.value },
  )
  if (result) resetHook()
}
async function history(hook, targetPage = 1) {
  const current = revision
  try {
    const result = await api.request(`${root}/webhooks/${hook.id}/deliveries?page=${targetPage}`)
    if (current === revision) {
      selected.value = hook
      deliveries.value = result
      page.value = targetPage
    }
  } catch (cause) {
    if (current === revision) error.value = cause
  }
}
async function confirmAction(key, path, method = 'POST') {
  if (window.confirm(t('integrations.' + key))) return action(path, method)
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
      <p v-if="error" class="field-error" role="alert">{{ errorMessage }}</p>
      <p v-if="message" role="status" class="account-success">{{ t(message) }}</p>
      <div class="integration-tabs" role="tablist" :aria-label="t('integrations.title')">
        <button
          v-for="item in ['tokens', 'webhooks']"
          :id="'integration-tab-' + item"
          :key="item"
          role="tab"
          :aria-selected="tab === item"
          :aria-controls="'integration-panel-' + item"
          class="button"
          :class="tab === item ? 'primary' : 'secondary'"
          @click="selectTab(item)"
        >
          {{ t('integrations.' + item) }}
        </button>
      </div>
      <p v-if="loading" role="status">{{ t('nav.loading') }}</p>
      <button
        v-else-if="!state"
        class="button secondary"
        @click="load().catch((cause) => (error = cause))"
      >
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
          <section class="surface integration-list">
            <h2>{{ t('integrations.tokens') }}</h2>
            <p v-if="!state.tokens.length" class="muted">{{ t('integrations.empty') }}</p>
            <article v-for="token in state.tokens" :key="token.id" class="integration-item">
              <div>
                <h3>{{ token.name }}</h3>
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
                @click="confirmAction('confirmRevoke', '/tokens/' + token.id, 'DELETE')"
              >
                {{ t('integrations.revoke') }}
              </button>
            </article>
          </section>
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
                :disabled="busy || !events.length"
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
            <h2>Webhooks</h2>
            <p v-if="!state.webhooks.length" class="muted">{{ t('integrations.empty') }}</p>
            <article
              v-for="hook in state.webhooks"
              :key="hook.id"
              class="integration-item webhook-item"
            >
              <h3>{{ hook.name }}</h3>
              <p class="integration-url">{{ hook.url }}</p>
              <p class="small muted">{{ hook.events.map(eventLabel).join(' · ') }}</p>
              <label class="integration-toggle"
                ><input
                  type="checkbox"
                  :checked="hook.enabled"
                  :disabled="busy"
                  @change="
                    action('/webhooks/' + hook.id, 'PATCH', { enabled: $event.target.checked })
                  "
                />{{ t('integrations.enabled') }}</label
              >
              <div class="integration-actions">
                <button class="button secondary" :disabled="busy" @click="edit(hook)">
                  {{ t('integrations.edit') }}</button
                ><button
                  class="button secondary"
                  :disabled="busy || !hook.enabled"
                  @click="action('/webhooks/' + hook.id + '/test', 'POST', undefined, true)"
                >
                  {{ t('integrations.test') }}</button
                ><button class="button secondary" :disabled="busy" @click="history(hook)">
                  {{ t('integrations.history') }}</button
                ><button
                  class="text-button"
                  :disabled="busy"
                  @click="confirmAction('confirmRotate', '/webhooks/' + hook.id + '/rotate')"
                >
                  {{ t('integrations.rotate') }}</button
                ><button
                  class="text-button danger"
                  :disabled="busy"
                  @click="
                    confirmAction('confirmDelete', '/webhooks/' + hook.id, 'DELETE')
                      .then(() => {
                        selected = null
                        deliveries = null
                      })
                      .catch(() => {})
                  "
                >
                  {{ t('integrations.delete') }}
                </button>
              </div>
            </article>
          </section>
          <section v-if="deliveries && selected" class="surface integration-list">
            <div class="integration-actions">
              <h2>{{ t('integrations.history') }} · {{ selected.name }}</h2>
              <button class="button secondary" :disabled="busy" @click="history(selected, page)">
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
                    true,
                  )
                "
              >
                {{ t('integrations.retry') }}
              </button>
            </article>
            <div class="integration-actions">
              <button
                class="button secondary"
                :disabled="page <= 1 || busy"
                @click="history(selected, page - 1)"
              >
                {{ t('integrations.previous') }}</button
              ><span>{{ page }} / {{ deliveries.meta.lastPage }}</span
              ><button
                class="button secondary"
                :disabled="page >= deliveries.meta.lastPage || busy"
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
        <button class="button primary" @click="copy">
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
