<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import LoadingButton from '../components/LoadingButton.vue'
import AppIcon from '../components/AppIcon.vue'
const route = useRoute()
const email = ref(''),
  pending = ref(false),
  sent = ref(false),
  error = ref(null)
const emailInput = ref(null),
  sentHeading = ref(null)
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
onBeforeUnmount(() => revision++)
async function useAnotherEmail() {
  if (pending.value) return
  sent.value = false
  error.value = null
  await nextTick()
  emailInput.value?.focus()
}
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
    if (current !== revision) return
    sent.value = true
    await nextTick()
    if (current === revision) sentHeading.value?.focus()
  } catch (cause) {
    if (current === revision) error.value = cause
  } finally {
    if (current === revision) pending.value = false
  }
}
</script>
<template>
  <section class="surface client-project-login">
    <template v-if="sent">
      <div class="client-entry-sent" role="status">
        <AppIcon name="mail" :size="40" class="client-entry-icon" />
        <h1 ref="sentHeading" tabindex="-1">{{ t('provisioning.loginSentTitle') }}</h1>
        <p class="muted">{{ t('provisioning.loginSent') }}</p>
      </div>
      <button type="button" class="button secondary client-entry-another" @click="useAnotherEmail">
        {{ t('provisioning.loginAnotherEmail') }}
      </button>
    </template>
    <template v-else>
      <div class="client-entry-heading">
        <h1>{{ t('provisioning.loginTitle') }}</h1>
        <p class="muted">{{ t('provisioning.loginHint') }}</p>
      </div>
      <form class="client-entry-form" @submit.prevent="login" :aria-busy="pending">
        <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
        <div class="field">
          <label for="client-login-email">{{ t('common.email') }}</label>
          <input
            id="client-login-email"
            ref="emailInput"
            v-model="email"
            type="email"
            autocomplete="email"
            required
            maxlength="255"
            :disabled="pending"
          />
        </div>
        <LoadingButton type="submit" class="button primary" :busy="pending" :disabled="pending">{{
          t('provisioning.loginSend')
        }}</LoadingButton>
      </form>
    </template>
  </section>
</template>
<style scoped>
.client-project-login {
  width: min(100%, 480px);
  margin: 24px auto;
  padding: clamp(20px, 4vw, 32px);
  display: grid;
  gap: 24px;
}
.client-entry-heading,
.client-entry-sent {
  display: grid;
  gap: 14px;
  text-align: center;
}
.client-project-login h1 {
  font-size: clamp(23px, 4vw, 28px);
  overflow-wrap: anywhere;
}
.client-project-login p {
  line-height: 1.6;
}
.client-entry-form {
  display: grid;
  gap: 20px;
}
.client-entry-icon {
  justify-self: center;
  color: var(--action);
}
.client-entry-another {
  justify-self: center;
  white-space: normal;
}
.client-entry-sent h1:focus {
  outline: none;
}
</style>
