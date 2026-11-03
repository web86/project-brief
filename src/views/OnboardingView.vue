<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { demo, STATIC_DEMO } from '../services/demo.js'
import { t } from '../i18n/index.js'
import { onboardingError, pendingRegistrationEmail } from '../services/onboarding.js'
import { appDestination } from '../services/session.js'
import LoadingButton from '../components/LoadingButton.vue'
import AppIcon from '../components/AppIcon.vue'
import { toast } from '../services/toast.js'
const router = useRouter(),
  route = useRoute()
const state = ref(null),
  email = ref(pendingRegistrationEmail.value),
  password = ref(''),
  newEmail = ref(''),
  token = ref(''),
  busy = ref(false),
  error = ref(''),
  notice = ref(''),
  now = ref(Date.now())
let current = true,
  timer
const cooldown = computed(
  () =>
    Math.max(0, Math.ceil((Date.parse(state.value?.resendAfter || '') - now.value) / 1000)) || 0,
)
const ready = computed(
  () => state.value?.step === 'complete' && state.value?.state === 'ready_for_trial_activation',
)
async function captureToken(hash) {
  const match = /^#token=([a-f0-9]{64})$/.exec(hash)
  if (!match) return false
  token.value = match[1]
  await router.replace({ path: route.path, query: route.query, hash: '' })
  return true
}
watch(
  () => route.hash,
  async (hash) => {
    if (demo.readOnly || STATIC_DEMO) return
    if (
      (await captureToken(hash)) &&
      state.value?.onboarding &&
      !state.value.emailVerified &&
      !busy.value
    )
      await confirm()
  },
)
async function load() {
  const result = await api.request('/api/onboarding/state')
  if (current) {
    state.value = result
    if (result.email) email.value = result.email
    if (result.onboarding && result.step === 'complete')
      for (const item of toast.items.value) if (item.type === 'success') toast.dismiss(item.id)
  }
  return result
}
onMounted(async () => {
  if (demo.readOnly || STATIC_DEMO) {
    state.value = { state: 'guest' }
    return
  }
  await captureToken(route.hash)
  timer = setInterval(() => {
    now.value = Date.now()
  }, 1000)
  busy.value = true
  try {
    const result = await load()
    if (token.value && result.onboarding && !result.emailVerified) await verify()
    else if (result.onboarding && result.nextPath !== route.path)
      await router.replace(result.nextPath)
  } catch (e) {
    if (current) error.value = onboardingError(e)
  } finally {
    if (current) busy.value = false
  }
})
onBeforeUnmount(() => {
  current = false
  clearInterval(timer)
  token.value = ''
  password.value = ''
  pendingRegistrationEmail.value = ''
})
async function signIn() {
  busy.value = true
  error.value = ''
  try {
    const result = await api.request('/api/admin/login', {
      method: 'POST',
      body: { email: email.value, password: password.value },
    })
    password.value = ''
    api.resetCsrf()
    if (result.onboarding && result.emailVerified === false) {
      await load()
      if (token.value) await verify()
    } else await router.replace((await appDestination(api.request)) || '/admin/security')
  } catch (e) {
    if (current) error.value = onboardingError(e)
  } finally {
    if (current) busy.value = false
  }
}
async function verify() {
  error.value = ''
  await api.request('/api/onboarding/verify', { method: 'POST', body: { token: token.value } })
  token.value = ''
  api.resetCsrf()
  const result = await load()
  if (current) await router.replace(result.nextPath)
}
async function confirm() {
  busy.value = true
  error.value = ''
  try {
    await verify()
  } catch (e) {
    if (current) error.value = onboardingError(e)
  } finally {
    if (current) busy.value = false
  }
}
async function resend() {
  busy.value = true
  error.value = ''
  try {
    await api.request('/api/onboarding/resend', { method: 'POST', body: { email: email.value } })
    if (current) {
      notice.value = t('onboarding.sent')
      await load()
    }
  } catch (e) {
    if (current) error.value = onboardingError(e)
  } finally {
    if (current) busy.value = false
  }
}
async function correct() {
  busy.value = true
  error.value = ''
  try {
    await api.request('/api/onboarding/email', {
      method: 'POST',
      body: { email: newEmail.value, current_password: password.value || undefined },
    })
    password.value = ''
    token.value = ''
    await load()
    notice.value = t('onboarding.sent')
  } catch (e) {
    if (current) error.value = onboardingError(e)
  } finally {
    if (current) busy.value = false
  }
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
    <p v-if="!state" role="status">{{ t('onboarding.loading') }}</p>
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
    <template v-else-if="state?.onboarding && state.emailVerified">
      <div class="completion-mark"><AppIcon name="clock" :size="28" /></div>
      <h1>{{ t('onboarding.preparingTitle') }}</h1>
      <p>{{ t('onboarding.preparingHint') }}</p>
      <LoadingButton class="button secondary" :busy="busy" @click="load">{{
        t('onboarding.refresh')
      }}</LoadingButton>
    </template>
    <template v-else>
      <div class="verification-mark"><AppIcon name="mail" :size="28" /></div>
      <h1>{{ t('onboarding.verification') }}</h1>
      <p>{{ t('onboarding.pending') }}</p>
      <form
        v-if="state && !state.onboarding && !demo.readOnly && !STATIC_DEMO"
        class="onboarding-form"
        @submit.prevent="signIn"
      >
        <p>{{ t('onboarding.signInToVerify') }}</p>
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
      <div v-if="state?.onboarding" class="onboarding-form">
        <p>{{ state.email }}</p>
        <LoadingButton v-if="token" class="button primary" :busy="busy" @click="confirm">{{
          t('onboarding.verify')
        }}</LoadingButton>
        <details>
          <summary>{{ t('onboarding.correction') }}</summary>
          <form class="onboarding-form" @submit.prevent="correct">
            <label>{{ t('common.email') }}<input v-model="newEmail" type="email" required /></label
            ><label v-if="state.passwordEnabled"
              >{{ t('onboarding.currentPassword')
              }}<input
                v-model="password"
                type="password"
                autocomplete="current-password"
                required /></label
            ><LoadingButton type="submit" class="button secondary" :busy="busy">{{
              t('onboarding.saveEmail')
            }}</LoadingButton>
          </form>
        </details>
      </div>
      <form class="onboarding-form" @submit.prevent="resend">
        <label v-if="!state?.onboarding"
          >{{ t('common.email') }}<input v-model="email" type="email" required
        /></label>
        <p v-if="cooldown" role="status">{{ t('onboarding.cooldown', { seconds: cooldown }) }}</p>
        <LoadingButton
          type="submit"
          class="button secondary"
          :busy="busy"
          :disabled="cooldown > 0 || demo.readOnly || STATIC_DEMO"
          >{{ t('onboarding.resend') }}</LoadingButton
        >
      </form>
      <button v-if="state?.onboarding" class="text-button" @click="logout">
        {{ t('onboarding.logout') }}
      </button>
      <RouterLink to="/admin/login">{{ t('onboarding.login') }}</RouterLink>
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
