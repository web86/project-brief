<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import LoadingButton from '../components/LoadingButton.vue'
import LanguageSwitcher from '../components/LanguageSwitcher.vue'
import ThemeSwitcher from '../components/ThemeSwitcher.vue'
const route = useRoute(),
  router = useRouter(),
  resetting = computed(() => route.path.endsWith('/reset-password'))
const params = new URLSearchParams(route.hash.slice(1)),
  token = params.get('token') || ''
const email = ref(params.get('email') || ''),
  password = ref(''),
  confirmation = ref(''),
  busy = ref(false),
  error = ref(''),
  success = ref('')
if (route.hash) router.replace({ path: route.path, hash: '' })
async function submit() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    await api.request('/api/admin/password/' + (resetting.value ? 'reset' : 'forgot'), {
      method: 'POST',
      body: {
        email: email.value,
        ...(resetting.value
          ? { token, password: password.value, password_confirmation: confirmation.value }
          : {}),
      },
    })
    success.value = t(resetting.value ? 'account.resetDone' : 'account.resetSent')
    password.value = ''
    confirmation.value = ''
    api.resetCsrf()
  } catch (cause) {
    error.value =
      resetting.value && cause.status === 422 ? t('account.invalidLink') : formatApiError(cause)
  } finally {
    busy.value = false
  }
}
</script>
<template>
  <div class="admin-login">
    <div class="appearance-controls"><ThemeSwitcher /><LanguageSwitcher /></div>
    <h1>{{ t(resetting ? 'account.reset' : 'account.recovery') }}</h1>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <p v-if="success" class="account-success" role="status">{{ success }}</p>
    <form class="surface admin-form" @submit.prevent="submit">
      <div class="field">
        <label for="recovery-email">{{ t('common.email') }}</label
        ><input
          id="recovery-email"
          v-model="email"
          type="email"
          autocomplete="username"
          maxlength="254"
          required
        />
      </div>
      <template v-if="resetting"
        ><p class="small muted">{{ t('account.passwordHelp') }}</p>
        <div class="field">
          <label for="recovery-password">{{ t('account.newPassword') }}</label
          ><input
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
          <label for="recovery-confirmation">{{ t('account.confirmPassword') }}</label
          ><input
            id="recovery-confirmation"
            v-model="confirmation"
            type="password"
            autocomplete="new-password"
            minlength="9"
            maxlength="1024"
            required
          /></div
      ></template>
      <LoadingButton type="submit" class="button primary" :busy="busy" :disabled="busy">{{
        t(resetting ? 'account.reset' : 'account.sendReset')
      }}</LoadingButton
      ><RouterLink to="/admin/login">{{ t('account.backLogin') }}</RouterLink>
    </form>
  </div>
</template>
