<script setup>
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import LoadingButton from '../components/LoadingButton.vue'
const route = useRoute()
const email = ref(''),
  pending = ref(false),
  sent = ref(false),
  error = ref(null)
let revision = 0
watch(
  () => route.params.uuid,
  () => {
    revision++
    email.value = ''
    pending.value = false
    sent.value = false
    error.value = null
  },
)
async function login() {
  if (pending.value) return
  const current = revision
  pending.value = true
  error.value = null
  try {
    await api.request(`/api/client/project/${route.params.uuid}/login`, {
      method: 'POST',
      body: { email: email.value },
    })
    if (current === revision) sent.value = true
  } catch (cause) {
    if (current === revision) error.value = cause
  } finally {
    if (current === revision) pending.value = false
  }
}
</script>
<template>
  <div class="admin-login client-project-login">
    <div class="page-heading">
      <h1>{{ t('provisioning.loginTitle') }}</h1>
    </div>
    <p>{{ t('provisioning.loginHint') }}</p>
    <p v-if="sent" role="status">{{ t('provisioning.loginSent') }}</p>
    <form v-else class="surface admin-form" @submit.prevent="login">
      <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
      <label class="field" for="client-login-email"
        >{{ t('common.email') }}
        <input
          id="client-login-email"
          v-model="email"
          type="email"
          autocomplete="email"
          required
          maxlength="255"
          :disabled="pending"
        />
      </label>
      <LoadingButton type="submit" class="button primary" :busy="pending" :disabled="pending">{{
        t('provisioning.loginSend')
      }}</LoadingButton>
    </form>
  </div>
</template>
