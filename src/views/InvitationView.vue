<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import LoadingButton from '../components/LoadingButton.vue'
import LanguageSwitcher from '../components/LanguageSwitcher.vue'
import ThemeSwitcher from '../components/ThemeSwitcher.vue'
const route = useRoute(),
  router = useRouter()
const token = /^#token=([a-f0-9]{64})$/.exec(route.hash)?.[1] || ''
router.replace({ path: route.path, hash: '' })
const state = ref(null),
  register = ref(false),
  name = ref(''),
  password = ref(''),
  confirmation = ref(''),
  error = ref(''),
  busy = ref(false)
async function resolve() {
  state.value = await api.request('/api/invitations/resolve', { method: 'POST', body: { token } })
  name.value ||= state.value.name || ''
}
onMounted(async () => {
  try {
    await resolve()
  } catch (cause) {
    error.value = cause.status === 410 || !token ? t('account.invalidLink') : formatApiError(cause)
  }
})
async function accept() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    if (!state.value.authenticated && !register.value) {
      await api.request('/api/admin/login', {
        method: 'POST',
        body: { email: state.value.email, password: password.value, invitation_token: token },
      })
      api.resetCsrf()
    }
    await api.request('/api/invitations/accept', {
      method: 'POST',
      body: {
        token,
        ...(!state.value.authenticated && register.value
          ? {
              name: name.value,
              password: password.value,
              password_confirmation: confirmation.value,
            }
          : {}),
      },
    })
    api.resetCsrf()
    useAdminNavigationStore().clear()
    password.value = ''
    confirmation.value = ''
    await router.replace('/admin')
  } catch (cause) {
    error.value =
      cause.status === 410
        ? t('account.invalidLink')
        : [401, 403].includes(cause.status)
          ? t('account.wrongAccount')
          : formatApiError(cause)
  } finally {
    busy.value = false
  }
}
async function logout() {
  await api.request('/api/admin/logout', { method: 'POST' })
  api.resetCsrf()
  useAdminNavigationStore().clear()
  await resolve()
}
</script>
<template>
  <div class="admin-login invitation-page">
    <div class="appearance-controls"><ThemeSwitcher /><LanguageSwitcher /></div>
    <h1>{{ t('account.invitation') }}</h1>
    <p v-if="error" role="alert" class="field-error">{{ error }}</p>
    <form v-if="state" class="surface admin-form" @submit.prevent="accept">
      <h2>{{ t('account.invitedTo', { name: state.workspace }) }}</h2>
      <p>
        <strong>{{ state.email }}</strong>
      </p>
      <p class="muted">{{ t('account.invitationHelp') }}</p>
      <ul>
        <li v-for="project in state.projects" :key="project">{{ project }}</li>
      </ul>
      <div v-if="!state.authenticated" class="team-actions">
        <button
          type="button"
          class="button secondary"
          :aria-pressed="!register"
          @click="register = false"
        >
          {{ t('account.signIn') }}</button
        ><button
          type="button"
          class="button secondary"
          :aria-pressed="register"
          @click="register = true"
        >
          {{ t('account.register') }}
        </button>
      </div>
      <template v-if="!state.authenticated">
        <div v-if="register" class="field">
          <label for="invited-name">{{ t('account.name') }}</label
          ><input id="invited-name" v-model="name" autocomplete="name" maxlength="255" required />
        </div>
        <div class="field">
          <label for="invited-password">{{
            t(register ? 'account.newPassword' : 'ui.password')
          }}</label
          ><input
            id="invited-password"
            v-model="password"
            type="password"
            :autocomplete="register ? 'new-password' : 'current-password'"
            :minlength="register ? 9 : undefined"
            maxlength="1024"
            required
          />
        </div>
        <div v-if="register" class="field">
          <label for="invited-confirmation">{{ t('account.confirmPassword') }}</label
          ><input
            id="invited-confirmation"
            v-model="confirmation"
            type="password"
            autocomplete="new-password"
            minlength="9"
            maxlength="1024"
            required
          />
        </div>
      </template>
      <LoadingButton type="submit" class="button primary" :busy="busy" :disabled="busy">{{
        t('account.accept')
      }}</LoadingButton>
      <button
        v-if="state.authenticated"
        type="button"
        class="text-button"
        :disabled="busy"
        @click="logout"
      >
        {{ t('account.logout') }}</button
      ><RouterLink v-else to="/admin/forgot-password">{{ t('account.forgot') }}</RouterLink>
    </form>
  </div>
</template>
