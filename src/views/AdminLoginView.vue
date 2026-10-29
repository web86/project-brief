<script setup>
import { passkeyCeremony, passkeysSupported } from '../services/accountSecurity.js'
import LoadingButton from '../components/LoadingButton.vue'
import { t, formatApiError } from '../i18n/index.js'
import { toast } from '../services/toast.js'

import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { api } from '../api/client'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { useProjectStore } from '../stores/project'
const router = useRouter(),
  route = useRoute()
const store = useProjectStore()
const email = ref('')
const password = ref('')
const operatorToken = ref('')
const pending = ref(false)
const error = ref('')
async function login() {
  if (pending.value) return
  pending.value = true
  error.value = ''
  try {
    const result = await api.request('/api/admin/login', {
      method: 'POST',
      body: {
        email: email.value,
        password: password.value,
        operator_recovery_token: operatorToken.value || undefined,
      },
    })
    operatorToken.value = ''
    api.resetCsrf()
    useAdminNavigationStore().clear()
    if (
      result.securityRecommended ||
      ['pending_2fa', 'enrollment_required'].includes(result.state)
    ) {
      password.value = ''
      await router.replace('/admin/security')
      return
    }
    store.admin = result.user
    store.apiError = ''
    store.sessionLost = false
    password.value = ''
    await router.replace(
      /^#email=[a-f0-9]{64}$/.test(route.hash)
        ? '/admin/account' + route.hash
        : /^#verify=[a-f0-9]{64}$/.test(route.hash)
          ? '/admin/settings/notifications' + route.hash
          : result.user?.platformAdmin
            ? '/platform'
            : '/admin',
    )
  } catch (cause) {
    error.value = cause
  } finally {
    pending.value = false
  }
}
async function loginPasskey() {
  if (pending.value) return
  pending.value = true
  error.value = ''
  try {
    const result = await passkeyCeremony('login')
    toast.success(() => t('security.identityConfirmed'))
    useAdminNavigationStore().clear()
    await router.replace(
      result.state === 'authenticated' && !result.recommendationsPending
        ? result.platformAdmin
          ? '/platform'
          : '/admin'
        : '/admin/security',
    )
  } catch (cause) {
    error.value = cause
    toast.error(() => formatApiError(cause))
  } finally {
    pending.value = false
  }
}
</script>
<template>
  <div class="admin-login">
    <div class="page-heading">
      <h1>{{ t('ui.signInAsAdministrator') }}</h1>
      <p>{{ t('ui.yourProjectsAndClientIdeasInOnePlace') }}</p>
    </div>
    <form class="surface admin-form" @submit.prevent="login">
      <div class="field">
        <label for="admin-email">{{ t('common.email') }}</label
        ><input
          @invalid="$event.target.setCustomValidity(t('flow.fieldError'))"
          @input="$event.target.setCustomValidity('')"
          id="admin-email"
          v-model="email"
          type="email"
          autocomplete="username"
          required
        />
      </div>
      <div class="field">
        <label for="admin-password">{{ t('ui.password') }}</label
        ><input
          @invalid="$event.target.setCustomValidity(t('flow.fieldError'))"
          @input="$event.target.setCustomValidity('')"
          id="admin-password"
          v-model="password"
          type="password"
          autocomplete="current-password"
          required
        />
      </div>
      <details>
        <summary>{{ t('security.operatorToken') }}</summary>
        <div class="field">
          <label for="operator-token">{{ t('security.operatorToken') }}</label
          ><input id="operator-token" v-model="operatorToken" autocomplete="off" maxlength="64" />
        </div>
      </details>
      <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
      <LoadingButton :busy="pending" class="button primary" type="submit" :disabled="pending">
        {{ t('ui.signIn') }}
      </LoadingButton>
      <button
        v-if="passkeysSupported()"
        type="button"
        class="button secondary"
        :disabled="pending"
        @click="loginPasskey"
      >
        {{ t('security.signInPasskey') }}
      </button>
      <p v-else class="small muted">{{ t('security.unsupported') }}</p>
      <RouterLink to="/admin/forgot-password">{{ t('account.forgot') }}</RouterLink>
    </form>
  </div>
</template>
