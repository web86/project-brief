<script setup>
import LoadingButton from '../components/LoadingButton.vue'
import { t, formatApiError } from '../i18n/index.js'

import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api/client'
import { useProjectStore } from '../stores/project'
const router = useRouter()
const store = useProjectStore()
const email = ref('')
const password = ref('')
const pending = ref(false)
const error = ref('')
async function login() {
  if (pending.value) return
  pending.value = true
  error.value = ''
  try {
    const result = await api.request('/api/admin/login', {
      method: 'POST',
      body: { email: email.value, password: password.value },
    })
    store.admin = result.user
    store.apiError = ''
    store.sessionLost = false
    password.value = ''
    await router.push('/admin')
  } catch (cause) {
    error.value = cause
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
      <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
      <LoadingButton :busy="pending" class="button primary" type="submit" :disabled="pending">
        {{ t('ui.signIn') }}
      </LoadingButton>
    </form>
  </div>
</template>
