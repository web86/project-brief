<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import LoadingButton from '../components/LoadingButton.vue'
const route = useRoute(),
  router = useRouter(),
  resetting = computed(() => route.path.endsWith('/reset-password'))
const params = new URLSearchParams(route.hash.slice(1)),
  token = params.get('token') || ''
const email = ref(params.get('email') || ''),
  password = ref(''),
  confirmation = ref(''),
  code = ref(''),
  recovery = ref(''),
  useRecovery = ref(false),
  totp = ref(false),
  checking = ref(resetting.value),
  valid = ref(false),
  busy = ref(false),
  error = ref(''),
  success = ref('')
let redirectTimer,
  disposed = false
if (route.hash) router.replace({ path: route.path, hash: '' })
onBeforeUnmount(() => {
  disposed = true
  clearTimeout(redirectTimer)
})
onMounted(async () => {
  if (!resetting.value) return
  try {
    const state = await api.request('/api/admin/password/inspect', {
      method: 'POST',
      body: { email: email.value, token },
    })
    if (!disposed) {
      totp.value = state.totp
      valid.value = true
    }
  } catch (cause) {
    if (!disposed)
      error.value = cause.status === 422 ? t('account.invalidLink') : formatApiError(cause)
  } finally {
    if (!disposed) checking.value = false
  }
})
function toggleFactor() {
  useRecovery.value = !useRecovery.value
  code.value = ''
  recovery.value = ''
}
async function submit() {
  if (busy.value || success.value || checking.value || (resetting.value && !valid.value)) return
  busy.value = true
  error.value = ''
  try {
    await api.request('/api/admin/password/' + (resetting.value ? 'reset' : 'forgot'), {
      method: 'POST',
      body: {
        email: email.value,
        ...(resetting.value
          ? {
              token,
              password: password.value,
              password_confirmation: confirmation.value,
              ...(totp.value
                ? {
                    [useRecovery.value ? 'recovery_code' : 'code']: useRecovery.value
                      ? recovery.value
                      : code.value,
                  }
                : {}),
            }
          : {}),
      },
    })
    if (disposed) return
    success.value = t(resetting.value ? 'account.resetDone' : 'account.resetSent')
    password.value = confirmation.value = code.value = recovery.value = ''
    api.resetCsrf()
    if (resetting.value) redirectTimer = setTimeout(() => router.replace('/admin/login'), 1500)
  } catch (cause) {
    if (!disposed)
      error.value =
        cause.errors?.code || cause.errors?.recovery_code
          ? t('security.challengeHelp')
          : cause.errors?.password
            ? t('account.passwordCheck')
            : resetting.value && cause.status === 422
              ? t('account.invalidLink')
              : formatApiError(cause)
  } finally {
    if (!disposed) busy.value = false
  }
}
</script>
<template>
  <div class="admin-login">
    <h1>{{ t(resetting ? 'account.reset' : 'account.recovery') }}</h1>
    <p v-if="checking" role="status">{{ t('nav.loading') }}</p>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <div v-if="success" class="surface admin-form" role="status">
      <p class="account-success">{{ success }}</p>
      <RouterLink to="/admin/login" class="button primary">{{ t('account.backLogin') }}</RouterLink>
    </div>
    <form v-else-if="!resetting || valid" class="surface admin-form" @submit.prevent="submit">
      <fieldset :disabled="busy" class="reset-fields">
        <div v-if="!resetting" class="field">
          <label for="recovery-email">{{ t('common.email') }}</label>
          <input
            id="recovery-email"
            v-model="email"
            type="email"
            autocomplete="username"
            maxlength="254"
            required
          />
        </div>
        <template v-else>
          <p class="small muted">{{ t('account.passwordHelp') }}</p>
          <div class="field">
            <label for="recovery-password">{{ t('account.newPassword') }}</label>
            <input
              id="recovery-password"
              v-model="password"
              type="password"
              autocomplete="new-password"
              minlength="9"
              maxlength="1024"
              required
            />
          </div>
          <div class="field">
            <label for="recovery-confirmation">{{ t('account.confirmPassword') }}</label>
            <input
              id="recovery-confirmation"
              v-model="confirmation"
              type="password"
              autocomplete="new-password"
              minlength="9"
              maxlength="1024"
              required
            />
          </div>
          <template v-if="totp">
            <div v-if="!useRecovery" class="field">
              <label for="reset-totp">{{ t('security.code') }}</label>
              <input
                id="reset-totp"
                v-model="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9]{6}"
                maxlength="6"
                required
              />
            </div>
            <div v-else class="field">
              <label for="reset-recovery">{{ t('security.recoveryCode') }}</label>
              <input
                id="reset-recovery"
                v-model="recovery"
                autocomplete="off"
                maxlength="100"
                required
              />
            </div>
            <button class="text-button small" type="button" @click="toggleFactor">
              {{ t(useRecovery ? 'security.useTotp' : 'account.lostAuthenticator') }}
            </button>
          </template>
        </template>
        <LoadingButton type="submit" class="button primary" :busy="busy" :disabled="busy">{{
          t(resetting ? 'account.reset' : 'account.sendReset')
        }}</LoadingButton>
      </fieldset>
      <RouterLink to="/admin/login">{{ t('account.backLogin') }}</RouterLink>
    </form>
    <RouterLink v-else-if="!checking" to="/admin/forgot-password">{{
      t('account.recovery')
    }}</RouterLink>
  </div>
</template>
<style scoped>
.reset-fields {
  border: 0;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 16px;
  min-width: 0;
}
</style>
