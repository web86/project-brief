<script setup>
import { isSecurityCancellation } from '../services/stepUp.js'
import { onMounted, onBeforeUnmount, ref } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError, formatDate } from '../i18n/index.js'
import { passkeyCeremony, passkeysSupported } from '../services/accountSecurity.js'
import SecurityConfirmation from './SecurityConfirmation.vue'
import LoadingButton from './LoadingButton.vue'
import SecurityError from './SecurityError.vue'
import { toast } from '../services/toast.js'
const props = defineProps({ onboarding: Boolean })
const showPasskeyForm = ref(!props.onboarding)
const emit = defineEmits(['ready'])
const state = ref(null),
  setup = ref(null),
  codes = ref([]),
  code = ref(''),
  name = ref(''),
  busy = ref(false),
  error = ref(null)
const renaming = ref(null),
  rename = ref(''),
  deleting = ref(null),
  disablingTotp = ref(false),
  alternativePassword = ref(''),
  alternativeCode = ref(''),
  errorContext = ref('general')
async function load() {
  state.value = await api.request('/api/security/status')
  if (!state.value.fresh && !state.value.setupFresh && !state.value.recoveryFresh) {
    codes.value = []
    setup.value = null
  }
}
onMounted(() => action(load))
onBeforeUnmount(() => {
  alternativePassword.value = ''
  alternativeCode.value = ''
  code.value = ''
  codes.value = []
  setup.value = null
})
async function action(run, success, context = 'general') {
  if (busy.value) return
  busy.value = true
  error.value = null
  errorContext.value = context
  try {
    await run()
    if (success) toast.success(() => t(`security.${success}`))
  } catch (cause) {
    if (isSecurityCancellation(cause)) return
    error.value = cause
    if (cause.code === 'security_retry_required') toast.info(() => formatApiError(cause))
    else toast.error(() => formatApiError(cause))
    if ([401, 419, 423].includes(cause.status) || cause.credentialCreated) {
      try {
        await load()
        if (state.value.state === 'guest' || !state.value.fresh) errorContext.value = 'general'
      } catch {
        /* Keep the original operation error visible. */
      }
    }
  } finally {
    busy.value = false
  }
}
async function refreshed(result) {
  state.value = result
  api.resetCsrf()
}
function startTotp() {
  return action(
    async () => {
      setup.value = await api.request('/api/security/totp/setup', { method: 'POST' })
      api.resetCsrf()
    },
    'setupReady',
    'totp',
  )
}
function confirmTotp() {
  return action(
    async () => {
      const result = await api.request('/api/security/totp/confirm', {
        method: 'POST',
        body: { code: code.value },
      })
      codes.value = result.codes
      setup.value = null
      code.value = ''
      api.resetCsrf()
      await load()
    },
    'totpConfirmed',
    'totp',
  )
}
function recovery(regenerate = false) {
  return action(
    async () => {
      codes.value = (
        await api.request('/api/security/recovery-codes', { method: regenerate ? 'POST' : 'GET' })
      ).codes
      api.resetCsrf()
      await load()
    },
    regenerate ? 'securityUpdated' : 'recoveryShown',
    'totp',
  )
}
function acknowledge() {
  return action(
    async () => {
      await refreshed(
        await api.request('/api/security/recovery-codes/acknowledge', { method: 'POST' }),
      )
      codes.value = []
    },
    'recoverySaved',
    'totp',
  )
}
function register() {
  return action(
    async () => {
      await refreshed(await passkeyCeremony('register', name.value))
      name.value = ''
    },
    'passkeyAdded',
    'passkey',
  )
}
function beginRename(key) {
  renaming.value = key.id
  rename.value = key.name
}
function update(key) {
  return action(
    async () => {
      await refreshed(
        await api.request('/api/security/passkeys/' + key.id, {
          method: 'PATCH',
          body: { name: rename.value },
        }),
      )
      renaming.value = null
    },
    'securityUpdated',
    'passkey',
  )
}
function remove(key) {
  return action(
    async () => {
      await refreshed(
        await api.request('/api/security/passkeys/' + key.id, {
          method: 'DELETE',
          body: {
            current_password: alternativePassword.value,
            [/^\d{6}$/.test(alternativeCode.value) ? 'code' : 'recovery_code']:
              alternativeCode.value || null,
          },
        }),
      )
      deleting.value = null
      alternativePassword.value = ''
      alternativeCode.value = ''
    },
    'securityUpdated',
    'passkey',
  )
}
function disableTotp() {
  return action(
    async () => {
      await refreshed(
        await api.request('/api/security/totp', {
          method: 'DELETE',
          body: { current_password: alternativePassword.value },
        }),
      )
      disablingTotp.value = false
      alternativePassword.value = ''
    },
    'securityUpdated',
    'totp',
  )
}
function finish() {
  return action(async () => {
    await api.request('/api/security/recommendations/acknowledge', { method: 'POST' })
    emit('ready')
  }, 'securityUpdated')
}
</script>
<template>
  <section class="account-security-panel" :aria-busy="busy">
    <SecurityError v-if="errorContext === 'general'" :error="error" />
    <button v-if="!state && !busy" class="button secondary" @click="action(load)">
      {{ t('ui.tryAgain') }}
    </button>
    <template v-if="state">
      <p v-if="state.state === 'enrollment_required'" class="surface security-notice" role="status">
        {{ t('security.enrollmentHelp') }}
      </p>
      <RouterLink v-if="state.state === 'guest'" to="/admin/login">{{
        t('account.backLogin')
      }}</RouterLink>
      <SecurityConfirmation
        v-if="
          state.state !== 'guest' &&
          (state.state === 'pending_2fa' ||
            (state.state === 'enrollment_required' && !state.setupFresh && !state.recoveryFresh))
        "
        :pending="state.state === 'pending_2fa'"
        @confirmed="refreshed"
      />
      <div
        v-else-if="state.state !== 'guest'"
        :class="{ 'onboarding-security-grid': props.onboarding }"
      >
        <section class="surface admin-form">
          <h2>
            {{ t('security.authenticator') }}
            <span class="small muted">{{
              t(state.owner || state.platformAdmin ? 'security.required' : 'security.optional')
            }}</span>
          </h2>
          <p>{{ t(state.totp ? 'security.totpActive' : 'security.totpHelp') }}</p>
          <SecurityError v-if="errorContext === 'totp'" :error="error" />
          <button v-if="!setup" class="button secondary" :disabled="busy" @click="startTotp">
            {{ t(state.totp ? 'security.rotateTotp' : 'security.setupTotp') }}
          </button>
          <form v-if="setup" class="admin-form" @submit.prevent="confirmTotp">
            <img
              class="security-qr"
              :src="'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(setup.svg)"
              :alt="t('security.qrAlt')"
            />
            <p class="small muted">{{ t('security.secretHelp') }}</p>
            <label for="totp-uri">{{ t('security.setupUri') }}</label>
            <textarea id="totp-uri" :value="setup.uri" readonly rows="3" />
            <div class="field">
              <label for="totp-confirm">{{ t('security.code') }}</label
              ><input
                id="totp-confirm"
                v-model="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                pattern="[0-9]{6}"
                required
              />
            </div>
            <LoadingButton type="submit" class="button primary" :busy="busy" :disabled="busy">{{
              t('security.confirm')
            }}</LoadingButton>
            <button class="text-button" type="button" @click="setup = null">
              {{ t('account.cancel') }}
            </button>
          </form>
          <template v-if="state.totp">
            <template v-if="!(state.owner || state.platformAdmin)">
              <button
                class="text-button danger"
                :disabled="busy"
                @click="disablingTotp = !disablingTotp"
              >
                {{ t('security.disableTotp') }}
              </button>
              <form v-if="disablingTotp" class="admin-form" @submit.prevent="disableTotp">
                <p>{{ t('security.alternativeRequired') }}</p>
                <label for="disable-totp-password">{{ t('account.currentPassword') }}</label>
                <input
                  id="disable-totp-password"
                  v-model="alternativePassword"
                  type="password"
                  autocomplete="current-password"
                  maxlength="1024"
                  required
                />
                <LoadingButton
                  class="button secondary"
                  type="submit"
                  :busy="busy"
                  :disabled="busy"
                  >{{ t('security.disableTotp') }}</LoadingButton
                >
              </form>
            </template>
            <h3>{{ t('security.recoveryCodes') }}</h3>
            <p>{{ t('security.recoveryHelp') }}</p>
            <div class="security-actions">
              <button class="button secondary" :disabled="busy" @click="recovery()">
                {{ t('security.showCodes') }}
              </button>
              <button class="text-button" :disabled="busy" @click="recovery(true)">
                {{ t('security.regenerateCodes') }}
              </button>
            </div>
            <template v-if="codes.length">
              <ul class="security-codes">
                <li v-for="item in codes" :key="item">
                  <code>{{ item }}</code>
                </li>
              </ul>
              <button class="button primary" :disabled="busy" @click="acknowledge">
                {{ t('security.savedCodes') }}
              </button>
            </template>
          </template>
        </section>
        <section class="surface admin-form">
          <h2>
            {{ t('security.passkeys') }}
            <span class="small muted">{{ t('security.optional') }}</span>
          </h2>
          <p>
            {{
              t(
                state.owner || state.platformAdmin
                  ? 'security.passkeysRequired'
                  : 'security.passkeysOptional',
              )
            }}
          </p>
          <p v-if="!passkeysSupported()" role="status">{{ t('security.unsupported') }}</p>
          <p v-if="(state.owner || state.platformAdmin) && (!state.totp || !state.recoverySaved)">
            {{ t('security.finishTotp') }}
          </p>
          <template v-else-if="passkeysSupported()"
            ><button
              v-if="!showPasskeyForm"
              class="button secondary"
              :disabled="busy"
              @click="showPasskeyForm = true"
            >
              {{ t('security.addPasskey') }}
            </button>
            <form v-else class="admin-form" @submit.prevent="register">
              <SecurityError v-if="errorContext === 'passkey'" :error="error" />
              <div class="field">
                <label for="passkey-name">{{ t('security.deviceName') }}</label
                ><input id="passkey-name" v-model="name" maxlength="100" required />
              </div>
              <LoadingButton type="submit" class="button primary" :busy="busy" :disabled="busy">{{
                t('security.addPasskey')
              }}</LoadingButton>
            </form>
          </template>
          <ul v-if="state.passkeys?.length" class="security-passkeys">
            <li v-for="key in state.passkeys" :key="key.id">
              <strong>{{ key.name }}</strong>
              <p class="small muted">
                {{ t('security.created', { date: formatDate(key.createdAt) }) }}<br />{{
                  key.lastUsedAt
                    ? t('security.lastUsed', { date: formatDate(key.lastUsedAt) })
                    : t('security.neverUsed')
                }}
              </p>
              <div class="security-actions">
                <button class="text-button" :disabled="busy" @click="beginRename(key)">
                  {{ t('security.rename') }}
                </button>
                <button class="text-button" :disabled="busy" @click="deleting = key.id">
                  {{ t('security.remove') }}
                </button>
              </div>
              <form v-if="renaming === key.id" @submit.prevent="update(key)">
                <label :for="'rename-' + key.id">{{ t('security.deviceName') }}</label
                ><input :id="'rename-' + key.id" v-model="rename" required maxlength="100" /><button
                  class="button secondary"
                  :disabled="busy"
                >
                  {{ t('account.save') }}
                </button>
              </form>
              <div v-if="deleting === key.id" role="group" :aria-label="t('security.remove')">
                <p>{{ t('security.removeHelp') }}</p>
                <template v-if="state.passkeys.length === 1">
                  <p>{{ t('security.alternativeRequired') }}</p>
                  <label for="last-key-password">{{ t('account.currentPassword') }}</label>
                  <input
                    id="last-key-password"
                    v-model="alternativePassword"
                    type="password"
                    autocomplete="current-password"
                    maxlength="1024"
                  />
                  <template v-if="state.totp && state.confirmationMethod !== 'mfa'">
                    <label for="last-key-code">{{ t('security.alternativeCode') }}</label>
                    <input
                      id="last-key-code"
                      v-model="alternativeCode"
                      autocomplete="off"
                      maxlength="100"
                    />
                  </template>
                </template>
                <button class="button secondary" :disabled="busy" @click="remove(key)">
                  {{ t('security.remove') }}</button
                ><button class="text-button" @click="deleting = null">
                  {{ t('account.cancel') }}
                </button>
              </div>
            </li>
          </ul>
          <p v-if="state.passkeys.length === 1" class="small muted">
            {{ t('security.lastPasskey') }}
          </p>
        </section>
        <button
          v-if="props.onboarding && state.state === 'authenticated'"
          class="button secondary onboarding-continue"
          @click="finish"
        >
          {{ t(state.recommendationsPending ? 'security.later' : 'security.continue') }}
        </button>
      </div>
    </template>
  </section>
</template>
<style scoped>
.onboarding-security-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
  align-items: start;
}
.onboarding-security-grid > .admin-form {
  margin-bottom: 0;
}
.onboarding-security-grid > .surface {
  min-height: 0;
  height: auto;
  gap: 0.75rem;
}
.onboarding-security-grid > .surface h2 {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  align-items: center;
  margin: 0;
}
.onboarding-security-grid > .surface p {
  margin: 0;
}
.onboarding-security-grid > .onboarding-continue {
  justify-self: start;
  grid-column: 1 / -1;
}
.onboarding-security-grid > .security-actions {
  grid-column: 1 / -1;
}
@media (max-width: 700px) {
  .onboarding-security-grid {
    grid-template-columns: 1fr;
  }
}
.account-security-panel {
  display: grid;
  gap: 1rem;
}
.security-notice {
  padding: 1rem;
}
.security-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}
.security-codes {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 0.5rem;
  padding: 1rem;
  list-style: none;
  user-select: all;
}
.security-qr {
  width: 192px;
  height: 192px;
  background: #fff;
  padding: 0.75rem;
  box-sizing: content-box;
  max-width: calc(100% - 1.5rem);
}
.security-passkeys {
  list-style: none;
  padding: 0;
  display: grid;
  gap: 1rem;
}
.security-passkeys li {
  overflow-wrap: anywhere;
}
textarea {
  max-width: 100%;
  box-sizing: border-box;
}
</style>
