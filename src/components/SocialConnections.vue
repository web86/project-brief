<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api/client.js'
import { demo, STATIC_DEMO } from '../services/demo.js'
import { t } from '../i18n/index.js'
import { onboardingError } from '../services/onboarding.js'
import LoadingButton from './LoadingButton.vue'
const route = useRoute()
const callbackError = computed(() =>
  route.query.authError
    ? t(
        'onboarding.' +
          (route.query.authError === 'social_account_link_required'
            ? 'linkRequired'
            : 'oauthFailed'),
      )
    : '',
)
const state = ref(null),
  security = ref(null),
  busy = ref(false),
  error = ref(''),
  notice = ref(''),
  password = ref(''),
  confirmation = ref('')
let current = true
async function load() {
  if (STATIC_DEMO || demo.readOnly) return
  try {
    const [connections, status] = await Promise.all([
      api.request('/api/auth/connections'),
      api.request('/api/security/status'),
    ])
    if (current) {
      state.value = connections
      security.value = status
    }
  } catch (e) {
    if (current) error.value = onboardingError(e)
  }
}
onMounted(load)
onBeforeUnmount(() => {
  current = false
  password.value = ''
  confirmation.value = ''
})
async function action(provider, connect) {
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    if (connect) {
      const result = await api.request('/api/auth/' + provider + '/link', {
        method: 'POST',
        body: {},
      })
      const url = new URL(result.url)
      if (
        url.protocol !== 'https:' ||
        !['accounts.google.com', 'oauth.yandex.ru', 'github.com'].includes(url.hostname)
      )
        throw new Error('invalid_url')
      window.location.assign(url.href)
    } else {
      await api.request('/api/auth/' + provider, { method: 'DELETE' })
      await load()
    }
  } catch (e) {
    if (current) error.value = onboardingError(e)
  } finally {
    if (current) busy.value = false
  }
}
async function setPassword() {
  busy.value = true
  error.value = ''
  try {
    await api.request('/api/admin/account/password', {
      method: 'POST',
      body: { password: password.value, password_confirmation: confirmation.value },
    })
    password.value = ''
    confirmation.value = ''
    await load()
    notice.value = t('onboarding.passwordSaved')
  } catch (e) {
    if (current) error.value = onboardingError(e)
  } finally {
    if (current) busy.value = false
  }
}
</script>
<template>
  <section v-if="!STATIC_DEMO && !demo.readOnly && (state || error)" class="social-connections">
    <h2>{{ t('onboarding.connections') }}</h2>
    <p v-if="callbackError" role="alert" class="field-error">{{ callbackError }}</p>
    <p v-if="error" role="alert" class="field-error">{{ error }}</p>
    <p v-if="notice" role="status">{{ notice }}</p>
    <p v-if="!security?.totp && !security?.passkeys?.length" class="muted">
      {{ t('onboarding.securityRequired') }}
    </p>
    <div v-for="row in state?.connections || []" :key="row.provider" class="social-connection-row">
      <span
        ><strong>{{ t('onboarding.' + row.provider) }}</strong
        ><small
          >{{ t('onboarding.' + (row.connected ? 'connected' : 'disconnected'))
          }}<span v-if="!row.configured"> · {{ t('onboarding.unavailable') }}</span></small
        ></span
      >
      <LoadingButton
        class="button secondary"
        :busy="busy"
        :disabled="
          (!row.connected && !row.configured) || (!security?.totp && !security?.passkeys?.length)
        "
        @click="action(row.provider, !row.connected)"
        >{{ t('onboarding.' + (row.connected ? 'disconnect' : 'connect')) }}</LoadingButton
      >
    </div>
    <p>
      {{ t('onboarding.emailPassword') }} ·
      {{ t('onboarding.' + (state?.passwordEnabled ? 'connected' : 'disconnected')) }}
    </p>
    <form
      v-if="state && !state.passwordEnabled"
      class="social-password-form"
      @submit.prevent="setPassword"
    >
      <label
        >{{ t('onboarding.password')
        }}<input
          v-model="password"
          type="password"
          autocomplete="new-password"
          minlength="12"
          maxlength="1024"
          required
      /></label>
      <label
        >{{ t('onboarding.passwordConfirmation')
        }}<input
          v-model="confirmation"
          type="password"
          autocomplete="new-password"
          minlength="12"
          maxlength="1024"
          required
      /></label>
      <LoadingButton type="submit" :busy="busy" class="button primary">{{
        t('onboarding.configurePassword')
      }}</LoadingButton>
    </form>
  </section>
</template>
<style scoped>
.social-connections {
  margin-top: 24px;
  display: grid;
  gap: 14px;
  min-width: 0;
}
.social-connection-row {
  display: flex;
  gap: 16px;
  justify-content: space-between;
  align-items: center;
  padding: 14px 0;
  border-bottom: 1px solid var(--border);
  flex-wrap: wrap;
}
small {
  display: block;
  margin-top: 6px;
  color: var(--text-subtle);
}
.social-password-form,
label {
  display: grid;
  gap: 10px;
}
input {
  width: 100%;
  min-height: 44px;
  background: var(--input-bg);
  border: 1px solid var(--input-border);
  border-radius: 8px;
  padding: 10px 12px;
  color: var(--text);
  font: inherit;
}
</style>
