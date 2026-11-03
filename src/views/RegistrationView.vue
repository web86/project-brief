<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { demo, STATIC_DEMO } from '../services/demo.js'
import { t, locale } from '../i18n/index.js'
import { onboardingError, pendingRegistrationEmail } from '../services/onboarding.js'
import LoadingButton from '../components/LoadingButton.vue'
import SocialSignIn from '../components/SocialSignIn.vue'
const route = useRoute(),
  router = useRouter()
const readiness = ref(null),
  email = ref(''),
  password = ref(''),
  confirmation = ref(''),
  busy = ref(false),
  error = ref('')
let current = true
onMounted(async () => {
  if (demo.readOnly || STATIC_DEMO) {
    readiness.value = { registrationEnabled: false }
    return
  }
  try {
    const value = await api.request('/api/onboarding/readiness')
    if (current) readiness.value = value
  } catch (e) {
    if (current) error.value = onboardingError(e)
  }
})
onBeforeUnmount(() => {
  current = false
  password.value = ''
  confirmation.value = ''
})
async function submit() {
  busy.value = true
  error.value = ''
  try {
    if (route.query.providerEmail === '1') {
      const result = await api.request('/api/auth/provider-email', {
        method: 'POST',
        body: { email: email.value },
      })
      if (current)
        await router.replace(
          result.path === '/admin/security' ? result.path : '/admin/verify-email',
        )
    } else {
      await api.request('/api/onboarding/register', {
        method: 'POST',
        body: {
          email: email.value,
          password: password.value,
          password_confirmation: confirmation.value,
          locale: locale.value,
        },
      })
      pendingRegistrationEmail.value = email.value
      password.value = ''
      confirmation.value = ''
      if (current) await router.replace('/admin/verify-email')
    }
  } catch (e) {
    if (current) error.value = onboardingError(e)
  } finally {
    if (current) busy.value = false
  }
}
</script>
<template>
  <section class="registration-page">
    <h1>{{ t('onboarding.create') }}</h1>
    <p class="muted">{{ t('onboarding.trial') }}</p>
    <p v-if="error" role="alert" class="field-error">{{ error }}</p>
    <p v-if="readiness && !readiness.registrationEnabled" class="surface registration-closed">
      {{ t('onboarding.closed') }}
    </p>
    <form v-else-if="readiness" class="surface registration-form" @submit.prevent="submit">
      <p v-if="route.query.providerEmail === '1'">{{ t('onboarding.providerEmailHint') }}</p>
      <label
        >{{ t('common.email')
        }}<input v-model="email" type="email" autocomplete="username" maxlength="254" required
      /></label>
      <template v-if="route.query.providerEmail !== '1'">
        <label
          >{{ t('onboarding.password')
          }}<input
            v-model="password"
            type="password"
            minlength="12"
            maxlength="1024"
            autocomplete="new-password"
            required
          /><small class="muted">{{ t('onboarding.passwordHint') }}</small></label
        >
        <label
          >{{ t('onboarding.passwordConfirmation')
          }}<input
            v-model="confirmation"
            type="password"
            minlength="12"
            maxlength="1024"
            autocomplete="new-password"
            required
        /></label>
      </template>
      <LoadingButton type="submit" class="button primary" :busy="busy">{{
        t('onboarding.' + (route.query.providerEmail === '1' ? 'continue' : 'signUp'))
      }}</LoadingButton>
      <SocialSignIn v-if="route.query.providerEmail !== '1'" />
    </form>
    <RouterLink to="/admin/login">{{ t('onboarding.login') }}</RouterLink>
  </section>
</template>
<style scoped>
.registration-page {
  max-width: 510px;
  width: 100%;
  margin: 32px auto;
  display: grid;
  gap: 20px;
  padding: 0 16px;
}
.registration-form {
  display: grid;
  gap: 20px;
  padding: 24px;
  min-width: 0;
}
label {
  display: grid;
  gap: 8px;
}
input {
  width: 100%;
  min-height: 44px;
  padding: 10px 12px;
  border: 1px solid var(--input-border);
  border-radius: 8px;
  background: var(--input-bg);
  color: var(--text);
  font: inherit;
}
.registration-closed {
  padding: 24px;
}
</style>
