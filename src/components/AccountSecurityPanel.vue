<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError, formatDate } from '../i18n/index.js'
import { passkeyCeremony, passkeysSupported } from '../services/accountSecurity.js'
import SecurityConfirmation from './SecurityConfirmation.vue'
import LoadingButton from './LoadingButton.vue'
const props = defineProps({ onboarding: Boolean })
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
  deleting = ref(null)
async function load() {
  state.value = await api.request('/api/security/status')
  if (!state.value.fresh) {
    codes.value = []
    setup.value = null
  }
}
onMounted(() => action(load))
onBeforeUnmount(() => {
  codes.value = []
  setup.value = null
})
async function action(run) {
  if (busy.value) return
  busy.value = true
  error.value = null
  try {
    await run()
  } catch (cause) {
    error.value = cause
    if (cause.status === 423) await load()
  } finally {
    busy.value = false
  }
}
async function refreshed(result) {
  state.value = result
  api.resetCsrf()
}
function startTotp() {
  return action(async () => {
    setup.value = await api.request('/api/security/totp/setup', { method: 'POST' })
    api.resetCsrf()
  })
}
function confirmTotp() {
  return action(async () => {
    const result = await api.request('/api/security/totp/confirm', {
      method: 'POST',
      body: { code: code.value },
    })
    codes.value = result.codes
    setup.value = null
    code.value = ''
    api.resetCsrf()
    await load()
  })
}
function recovery(regenerate = false) {
  return action(async () => {
    codes.value = (
      await api.request('/api/security/recovery-codes', { method: regenerate ? 'POST' : 'GET' })
    ).codes
    api.resetCsrf()
    await load()
  })
}
function acknowledge() {
  return action(async () => {
    await refreshed(
      await api.request('/api/security/recovery-codes/acknowledge', { method: 'POST' }),
    )
    codes.value = []
  })
}
function register() {
  return action(async () => {
    try {
      await refreshed(await passkeyCeremony('register', name.value))
      name.value = ''
    } catch {
      throw { messageKey: 'security.passkeyFailed' }
    }
  })
}
function beginRename(key) {
  renaming.value = key.id
  rename.value = key.name
}
function update(key) {
  return action(async () => {
    await refreshed(
      await api.request('/api/security/passkeys/' + key.id, {
        method: 'PATCH',
        body: { name: rename.value },
      }),
    )
    renaming.value = null
  })
}
function remove(key) {
  return action(async () => {
    await refreshed(await api.request('/api/security/passkeys/' + key.id, { method: 'DELETE' }))
    deleting.value = null
  })
}
</script>
<template>
  <section class="account-security-panel" :aria-busy="busy">
    <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
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
        v-if="state.state !== 'guest' && (state.state === 'pending_2fa' || !state.fresh)"
        :pending="state.state === 'pending_2fa'"
        @confirmed="refreshed"
      />
      <template v-else>
        <section v-if="state.owner" class="surface admin-form">
          <h2>{{ t('security.authenticator') }}</h2>
          <p>{{ t(state.totp ? 'security.totpActive' : 'security.totpHelp') }}</p>
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
          <h2>{{ t('security.passkeys') }}</h2>
          <p>{{ t(state.owner ? 'security.passkeysRequired' : 'security.passkeysOptional') }}</p>
          <p v-if="!passkeysSupported()" role="status">{{ t('security.unsupported') }}</p>
          <p v-if="state.owner && (!state.totp || !state.recoverySaved)">
            {{ t('security.finishTotp') }}
          </p>
          <form v-else-if="passkeysSupported()" class="admin-form" @submit.prevent="register">
            <div class="field">
              <label for="passkey-name">{{ t('security.deviceName') }}</label
              ><input id="passkey-name" v-model="name" maxlength="100" required />
            </div>
            <LoadingButton type="submit" class="button primary" :busy="busy" :disabled="busy">{{
              t('security.addPasskey')
            }}</LoadingButton>
          </form>
          <ul class="security-passkeys">
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
                <button
                  class="text-button"
                  :disabled="busy || (state.owner && state.passkeys.length === 1)"
                  @click="deleting = key.id"
                >
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
                <button class="button secondary" :disabled="busy" @click="remove(key)">
                  {{ t('security.remove') }}</button
                ><button class="text-button" @click="deleting = null">
                  {{ t('account.cancel') }}
                </button>
              </div>
            </li>
          </ul>
          <p v-if="state.owner && state.passkeys.length === 1" class="small muted">
            {{ t('security.lastPasskey') }}
          </p>
        </section>
        <button
          v-if="props.onboarding && state.state === 'authenticated'"
          class="button primary"
          @click="emit('ready')"
        >
          {{ t('security.continue') }}
        </button>
      </template>
    </template>
  </section>
</template>
<style scoped>
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
