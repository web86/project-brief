<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { demo, STATIC_DEMO } from '../services/demo.js'
import { t } from '../i18n/index.js'
import { onboardingError, pendingRegistrationEmail } from '../services/onboarding.js'
import { appDestination } from '../services/session.js'
import LoadingButton from '../components/LoadingButton.vue'
import AccountSecurityPanel from '../components/AccountSecurityPanel.vue'
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
  securityOpen = ref(Boolean(route.query.connected || route.query.authError)),
  now = ref(Date.now())
let current = true,
  timer
const cooldown = computed(
  () =>
    Math.max(0, Math.ceil((Date.parse(state.value?.resendAfter || '') - now.value) / 1000)) || 0,
)
const ready = computed(() => state.value?.emailVerified === true)
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
    if ((await captureToken(hash)) && state.value?.onboarding && !ready.value && !busy.value)
      await confirm()
  },
)
async function load() {
  const result = await api.request('/api/onboarding/state')
  if (current) {
    state.value = result
    if (result.email) email.value = result.email
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
    else if (result.onboarding && result.emailVerified && result.nextPath === '/admin/security')
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
  <section class="onboarding-page">
    <h1>{{ t('onboarding.' + (ready ? 'ready' : 'verification')) }}</h1>
    <p v-if="error" role="alert" class="field-error">{{ error }}</p>
    <p v-if="notice" role="status">{{ notice }}</p>
    <template v-if="ready">
      <p>{{ t('onboarding.prepared') }}</p>
      <details :open="securityOpen" @toggle="securityOpen = $event.target.open">
        <summary>{{ t('onboarding.security') }}</summary>
        <AccountSecurityPanel v-if="securityOpen" />
      </details>
      <button class="text-button" @click="logout">{{ t('onboarding.logout') }}</button>
    </template>
    <template v-else>
      <p>{{ t('onboarding.pending') }}</p>
      <form
        v-if="state && !state.onboarding && !demo.readOnly && !STATIC_DEMO"
        class="surface onboarding-form"
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
      <div v-if="state?.onboarding" class="surface onboarding-form">
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
  width: 100%;
  max-width: 660px;
  margin: 32px auto;
  display: grid;
  gap: 20px;
  padding: 0 16px;
}
.onboarding-form,
label {
  display: grid;
  gap: 12px;
  min-width: 0;
}
.surface {
  padding: 24px;
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
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 16px;
}
summary {
  cursor: pointer;
}
</style>
