<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { demo, STATIC_DEMO } from '../services/demo.js'
import { t } from '../i18n/index.js'
import {
  onboardingError,
  pendingRegistrationEmail,
  verificationResendAfter,
} from '../services/onboarding.js'
import { appDestination } from '../services/session.js'
import LoadingButton from '../components/LoadingButton.vue'
import AppIcon from '../components/AppIcon.vue'
import SecurityConfirmation from '../components/SecurityConfirmation.vue'
import { toast } from '../services/toast.js'

const router = useRouter()
const route = useRoute()
const state = ref(null)
const email = ref(pendingRegistrationEmail.value)
const password = ref('')
const newEmail = ref('')
const busy = ref(false)
const error = ref('')
const notice = ref('')
const invalid = ref(false)
const replayed = ref(false)
const pendingMfa = ref(null)
const now = ref(Date.now())
const readOnly = demo.readOnly || STATIC_DEMO
const guest = computed(() => state.value?.state === 'guest')
const address = computed(() => state.value?.email || pendingRegistrationEmail.value)
const callback = computed(
  () => route.path === '/admin/verify-email' && route.hash.startsWith('#token'),
)
const token = computed(() => callback.value && /^#token=([a-f0-9]{64})$/.exec(route.hash)?.[1])
const cooldown = computed(() => {
  const resendAfter = state.value?.onboarding
    ? Date.parse(state.value.resendAfter || '')
    : verificationResendAfter.value
  return Math.max(0, Math.ceil((resendAfter - now.value) / 1000)) || 0
})
const ready = computed(() => route.path === '/admin/onboarding' && state.value?.step === 'complete')
let current = true
let refreshPending = false
let timer

async function run(action) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await action()
  } catch (e) {
    if (current) error.value = onboardingError(e)
  } finally {
    busy.value = false
    if (current && refreshPending) {
      refreshPending = false
      void syncRoute()
    }
  }
}

async function load() {
  const result = await api.request('/api/onboarding/state')
  const status =
    result.state === 'guest' && callback.value ? await api.request('/api/security/status') : null
  if (current) {
    state.value = result
    pendingMfa.value = status?.state === 'pending_2fa' ? status : null
    if (result.emailVerified || result.state === 'existing_account') {
      pendingRegistrationEmail.value = ''
      verificationResendAfter.value = 0
    }
    if (result.onboarding && result.step === 'complete')
      for (const item of toast.items.value) if (item.type === 'success') toast.dismiss(item.id)
  }
  return result
}

function clearCallback() {
  return router.replace({ path: route.path, query: route.query, hash: '' })
}

async function verify(proof) {
  let result
  try {
    result = await api.request('/api/onboarding/verify', { method: 'POST', body: { token: proof } })
  } catch (e) {
    if (!current || token.value !== proof) return
    if (e.status === 401) await load()
    else if (e.status === 410 || e.status === 404) invalid.value = true
    else throw e
    return
  }
  api.resetCsrf()
  const verified = await load()
  if (!current || token.value !== proof) return
  if (result.replayed) {
    replayed.value = true
    await clearCallback()
  } else await router.replace(verified.nextPath || '/app')
}

async function syncRoute() {
  if (readOnly) {
    state.value = { state: 'guest' }
    return
  }
  if (busy.value) {
    refreshPending = true
    return
  }
  await run(async () => {
    invalid.value = false
    if (callback.value) replayed.value = false
    notice.value = ''
    const path = route.path
    const hash = route.hash
    const result = await load()
    if (!current || route.path !== path || route.hash !== hash) return
    if (callback.value) {
      if (!token.value) invalid.value = true
      else if (result.state !== 'guest') await verify(token.value)
    } else if (path === '/admin/onboarding' && result.onboarding && result.nextPath !== path) {
      await router.replace(result.nextPath)
    }
  })
}

watch(() => [route.path, route.hash], syncRoute, { immediate: true })
onMounted(() => {
  timer = setInterval(() => {
    now.value = Date.now()
  }, 1000)
})
onBeforeUnmount(() => {
  current = false
  clearInterval(timer)
  password.value = ''
  pendingRegistrationEmail.value = ''
  verificationResendAfter.value = 0
})

function signIn() {
  return run(async () => {
    await api.request('/api/admin/login', {
      method: 'POST',
      body: { email: email.value.trim(), password: password.value },
    })
    password.value = ''
    api.resetCsrf()
    const result = await load()
    if (current && token.value && result.state !== 'guest') await verify(token.value)
  })
}

function resend() {
  return run(async () => {
    const recipient = address.value || email.value.trim()
    await api.request('/api/onboarding/resend', { method: 'POST', body: { email: recipient } })
    if (!current) return
    if (guest.value) {
      pendingRegistrationEmail.value = recipient
      verificationResendAfter.value = Date.now() + 60_000
    } else await load()
    notice.value = t('onboarding.sent')
  })
}

function correct() {
  return run(async () => {
    await api.request('/api/onboarding/email', {
      method: 'POST',
      body: { email: newEmail.value, current_password: password.value || undefined },
    })
    password.value = ''
    newEmail.value = ''
    await load()
    notice.value = t('onboarding.sent')
  })
}

function switchAccount() {
  return run(async () => {
    await api.request('/api/admin/logout', { method: 'POST' })
    api.resetCsrf()
    email.value = ''
    password.value = ''
    invalid.value = false
    await load()
  })
}

function continueAccount() {
  return run(async () =>
    router.replace(state.value?.nextPath || (await appDestination(api.request)) || '/admin/login'),
  )
}

async function logout() {
  await api.request('/api/admin/logout', { method: 'POST' })
  api.resetCsrf()
  await router.replace('/admin/login')
}
</script>
<template>
  <section class="onboarding-page surface" :aria-busy="busy">
    <p v-if="error" role="alert" class="field-error">{{ error }}</p>
    <p v-if="notice" role="status">{{ notice }}</p>
    <header v-if="!state">
      <h1>{{ t('onboarding.verification') }}</h1>
      <p role="status">{{ t('onboarding.loading') }}</p>
    </header>
    <template v-else-if="ready">
      <div class="completion-mark"><AppIcon name="check" :size="28" /></div>
      <header>
        <h1>{{ t('onboarding.ready') }}</h1>
        <p class="muted">{{ t('onboarding.prepared') }}</p>
      </header>
      <ul class="readiness-checks">
        <li
          v-for="[check, label] in [
            ['emailVerified', 'emailComplete'],
            ['totpConfirmed', 'totpComplete'],
            ['recoveryAcknowledged', 'recoveryComplete'],
          ]"
          :key="check"
        >
          <AppIcon :name="state.checks[check] ? 'check' : 'clock'" :size="20" />{{
            t('onboarding.' + label)
          }}
        </li>
      </ul>
      <div class="activation-status" role="status">
        <AppIcon name="clock" :size="22" />
        <div>
          <h2>{{ t('onboarding.waitingTitle') }}</h2>
          <p>{{ t('onboarding.waitingHint') }}</p>
        </div>
      </div>
      <RouterLink class="button secondary security-settings-link" to="/admin/account/security"
        ><AppIcon name="shield" :size="20" />{{ t('onboarding.security') }}</RouterLink
      >
      <button class="text-button" @click="logout">{{ t('onboarding.logout') }}</button>
    </template>
    <template v-else-if="invalid">
      <div class="verification-mark"><AppIcon name="unlink" :size="28" /></div>
      <h1>{{ t('onboarding.linkUnavailable') }}</h1>
      <p role="alert">{{ t('onboarding.linkUnavailableHint') }}</p>
      <LoadingButton class="button primary" :busy="busy" @click="clearCallback">{{
        t('onboarding.requestNewLink')
      }}</LoadingButton>
      <button v-if="!guest && token" class="text-button" :disabled="busy" @click="switchAccount">
        {{ t('onboarding.switchAccount') }}
      </button>
    </template>
    <template v-else-if="callback && pendingMfa">
      <div class="verification-mark"><AppIcon name="lock" :size="28" /></div>
      <h1>{{ t('onboarding.signInToVerify') }}</h1>
      <SecurityConfirmation
        pending
        quiet-success
        :initial-status="pendingMfa"
        @confirmed="syncRoute"
      />
    </template>
    <template v-else-if="callback && guest && !readOnly && !busy">
      <div class="verification-mark"><AppIcon name="lock" :size="28" /></div>
      <header>
        <h1>{{ t('onboarding.signInToVerify') }}</h1>
        <p>{{ t('onboarding.signInToVerifyHint') }}</p>
      </header>
      <form class="onboarding-form" @submit.prevent="signIn">
        <label
          >{{ t('common.email')
          }}<input v-model="email" type="email" autocomplete="username" maxlength="254" required
        /></label>
        <label
          >{{ t('onboarding.password')
          }}<input
            v-model="password"
            type="password"
            autocomplete="current-password"
            maxlength="1024"
            required
        /></label>
        <LoadingButton type="submit" class="button primary" :busy="busy">{{
          t('ui.signIn')
        }}</LoadingButton>
      </form>
    </template>
    <template v-else-if="callback && !readOnly">
      <div class="verification-mark"><AppIcon name="clock" :size="28" /></div>
      <h1>{{ t('onboarding.verification') }}</h1>
      <p v-if="busy" role="status">{{ t('onboarding.loading') }}</p>
      <LoadingButton v-else class="button primary" @click="syncRoute">{{
        t('onboarding.verify')
      }}</LoadingButton>
    </template>
    <template v-else-if="route.path === '/admin/onboarding' && state.emailVerified">
      <div class="completion-mark"><AppIcon name="clock" :size="28" /></div>
      <h1>{{ t('onboarding.preparingTitle') }}</h1>
      <p>{{ t('onboarding.preparingHint') }}</p>
      <LoadingButton class="button secondary" :busy="busy" @click="syncRoute">{{
        t('onboarding.refresh')
      }}</LoadingButton>
    </template>
    <template v-else-if="state.emailVerified || replayed || state.state === 'existing_account'">
      <div class="completion-mark"><AppIcon name="check" :size="28" /></div>
      <header>
        <h1>
          {{
            t(
              'onboarding.' +
                (state.emailVerified || replayed ? 'emailComplete' : 'accountSignedIn'),
            )
          }}
        </h1>
        <p>{{ t('onboarding.verificationContinueHint') }}</p>
      </header>
      <LoadingButton class="button primary" :busy="busy" @click="continueAccount">{{
        t('onboarding.continue')
      }}</LoadingButton>
    </template>
    <template v-else>
      <div class="verification-mark"><AppIcon name="mail" :size="28" /></div>
      <header>
        <h1>{{ t('onboarding.checkEmail') }}</h1>
        <p>{{ t('onboarding.checkEmailHint') }}</p>
      </header>
      <p v-if="address" class="verification-address">{{ address }}</p>
      <form v-if="!readOnly" class="onboarding-form" @submit.prevent="resend">
        <label v-if="!address"
          >{{ t('common.email')
          }}<input v-model="email" type="email" autocomplete="email" maxlength="254" required
        /></label>
        <p v-if="cooldown">{{ t('onboarding.cooldown', { seconds: cooldown }) }}</p>
        <LoadingButton type="submit" class="button primary" :busy="busy" :disabled="cooldown > 0">{{
          t('onboarding.resend')
        }}</LoadingButton>
      </form>
      <details v-if="state.onboarding">
        <summary>{{ t('onboarding.correction') }}</summary>
        <form class="onboarding-form" @submit.prevent="correct">
          <label>{{ t('common.email') }}<input v-model="newEmail" type="email" required /></label>
          <label v-if="state.passwordEnabled"
            >{{ t('onboarding.currentPassword')
            }}<input v-model="password" type="password" autocomplete="current-password" required
          /></label>
          <LoadingButton type="submit" class="button secondary" :busy="busy">{{
            t('onboarding.saveEmail')
          }}</LoadingButton>
        </form>
      </details>
      <button v-if="state.onboarding" class="text-button" @click="logout">
        {{ t('onboarding.logout') }}
      </button>
      <RouterLink v-else to="/admin/login">{{ t('onboarding.login') }}</RouterLink>
    </template>
  </section>
</template>
<style scoped>
.onboarding-page {
  width: calc(100% - 32px);
  max-width: 520px;
  margin: 32px auto;
  display: grid;
  gap: 16px;
  padding: 24px;
  min-height: 260px;
  align-content: start;
}
h1 {
  font-size: 1.65rem;
  line-height: 1.2;
  margin: 0;
  letter-spacing: -0.025em;
}
h2 {
  font-size: 1rem;
  margin: 0 0 8px;
}
p {
  margin: 0;
  line-height: 1.5;
}
header {
  display: grid;
  gap: 12px;
}
.completion-mark,
.verification-mark {
  width: 52px;
  height: 52px;
  display: grid;
  place-items: center;
  background: var(--selected-bg);
  color: var(--link);
  border-radius: 50%;
}
.readiness-checks {
  padding: 8px 0;
  margin: 0;
  display: grid;
  gap: 12px;
  list-style: none;
}
.readiness-checks li {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 0.95rem;
}
.readiness-checks :deep(svg) {
  color: var(--success);
  flex-shrink: 0;
}
.activation-status {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 20px 0;
  border-top: 1px solid var(--border);
}
.activation-status > :deep(svg) {
  flex-shrink: 0;
  color: var(--text-subtle);
}
.activation-status p {
  color: var(--text-subtle);
  font-size: 0.95rem;
}
.security-settings-link {
  gap: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 44px;
}
.onboarding-form,
label {
  display: grid;
  gap: 12px;
  min-width: 0;
}
input {
  min-height: 44px;
  width: 100%;
  padding: 10px 12px;
  background: var(--input-bg);
  border: 1px solid var(--input-border);
  border-radius: 8px;
  color: var(--text);
  font: inherit;
}
.verification-address {
  font-weight: 650;
  overflow-wrap: anywhere;
}
details {
  border-top: 1px solid var(--border);
  padding-top: 16px;
}
summary {
  cursor: pointer;
}
@media (max-width: 520px) {
  .onboarding-page {
    margin: 20px auto;
    padding: 24px 20px;
  }
}
</style>
