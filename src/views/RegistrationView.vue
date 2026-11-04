<script setup>
import { computed, ref, nextTick, watch, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { demo, STATIC_DEMO } from '../services/demo.js'
import { t, locale } from '../i18n/index.js'
import {
  onboardingError,
  pendingRegistrationEmail,
  verificationResendAfter,
} from '../services/onboarding.js'
import LoadingButton from '../components/LoadingButton.vue'
import SocialSignIn from '../components/SocialSignIn.vue'
import AppIcon from '../components/AppIcon.vue'
const route = useRoute(),
  router = useRouter()
const readiness = ref(null),
  email = ref(''),
  password = ref(''),
  confirmation = ref(''),
  busy = ref(false),
  error = ref(''),
  fields = ref({})
const invitationToken = computed(() => new URLSearchParams(route.hash.slice(1)).get('invite'))
const providerEmail = computed(() => !invitationToken.value && route.query.providerEmail === '1')
let current = true
watch(
  invitationToken,
  async (token, previous, onCleanup) => {
    let active = true
    onCleanup(() => {
      active = false
    })
    readiness.value = null
    error.value = ''
    if (demo.readOnly || STATIC_DEMO) {
      readiness.value = { registrationEnabled: false }
      return
    }
    try {
      const value = token
        ? await api.request('/api/onboarding/canary-readiness', {
            method: 'POST',
            body: { invitation_token: token },
          })
        : await api.request('/api/onboarding/readiness')
      if (active) readiness.value = value
    } catch (cause) {
      if (active) error.value = onboardingError(cause)
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  current = false
  password.value = ''
  confirmation.value = ''
})
async function submit(event) {
  if (busy.value || !readiness.value?.registrationEnabled) return
  const issues = {}
  if (!email.value.trim() || event.target.elements.email.validity.typeMismatch)
    issues.email = 'emailInvalid'
  if (!providerEmail.value) {
    if (password.value.length < 12) issues.password = 'passwordHint'
    if (!confirmation.value || confirmation.value !== password.value)
      issues.confirmation = 'passwordMismatch'
  }
  fields.value = issues
  error.value = ''
  if (Object.keys(issues).length) {
    await nextTick()
    event.target.querySelector('[aria-invalid=true]')?.focus()
    return
  }
  busy.value = true
  try {
    if (providerEmail.value) {
      const result = await api.request('/api/auth/provider-email', {
        method: 'POST',
        body: { email: email.value.trim() },
      })
      if (current)
        await router.replace(
          result.path === '/admin/security' ? result.path : '/admin/verify-email',
        )
    } else {
      await api.request('/api/onboarding/register', {
        method: 'POST',
        body: {
          email: email.value.trim(),
          password: password.value,
          password_confirmation: confirmation.value,
          locale: locale.value,
          ...(invitationToken.value ? { invitation_token: invitationToken.value } : {}),
        },
      })
      pendingRegistrationEmail.value = email.value.trim()
      verificationResendAfter.value = Date.now() + 60_000
      password.value = ''
      confirmation.value = ''
      if (current) await router.replace('/admin/verify-email')
    }
  } catch (cause) {
    if (current) error.value = onboardingError(cause)
  } finally {
    if (current) busy.value = false
  }
}
</script>
<template>
  <section class="registration-page surface" :aria-busy="busy">
    <header>
      <AppIcon
        v-if="readiness && !readiness.registrationEnabled"
        class="closed-icon"
        name="clock"
        :size="28"
      />
      <h1>
        {{ t('onboarding.' + (readiness && !readiness.registrationEnabled ? 'closed' : 'create')) }}
      </h1>
      <p
        :class="error && readiness?.registrationEnabled ? 'field-error' : 'muted'"
        :role="error && readiness?.registrationEnabled ? 'alert' : undefined"
      >
        {{
          error && readiness?.registrationEnabled
            ? error
            : t(
                'onboarding.' +
                  (readiness && !readiness.registrationEnabled ? 'closedHint' : 'registrationHint'),
              )
        }}
      </p>
    </header>
    <p v-if="!readiness && !error" role="status">{{ t('onboarding.loading') }}</p>
    <p v-if="readiness && !readiness.registrationEnabled" class="registration-closed" role="status">
      {{ t('onboarding.noTrialYet') }}
    </p>
    <form v-else-if="readiness" class="registration-form" novalidate @submit.prevent="submit">
      <p v-if="providerEmail">{{ t('onboarding.providerEmailHint') }}</p>
      <div class="registration-field">
        <label for="registration-email">{{ t('common.email') }}</label>
        <input
          id="registration-email"
          v-model="email"
          name="email"
          type="email"
          inputmode="email"
          autocomplete="username"
          enterkeyhint="next"
          maxlength="254"
          required
          :aria-invalid="!!fields.email"
          aria-describedby="registration-email-help"
          @input="delete fields.email"
        />
        <small
          id="registration-email-help"
          :class="{ 'field-error': fields.email }"
          :role="fields.email ? 'alert' : undefined"
          >{{ fields.email ? t('onboarding.' + fields.email) : '\u00a0' }}</small
        >
      </div>
      <template v-if="!providerEmail">
        <div class="registration-field">
          <label for="registration-password">{{ t('onboarding.password') }}</label>
          <input
            id="registration-password"
            v-model="password"
            type="password"
            minlength="12"
            maxlength="1024"
            autocomplete="new-password"
            enterkeyhint="next"
            required
            :aria-invalid="!!fields.password"
            aria-describedby="registration-password-help"
            @input="delete fields.password"
          />
          <small
            id="registration-password-help"
            :class="fields.password ? 'field-error' : 'muted'"
            :role="fields.password ? 'alert' : undefined"
            >{{ t('onboarding.passwordHint') }}</small
          >
        </div>
        <div class="registration-field">
          <label for="registration-confirmation">{{ t('onboarding.passwordConfirmation') }}</label>
          <input
            id="registration-confirmation"
            v-model="confirmation"
            type="password"
            minlength="12"
            maxlength="1024"
            autocomplete="new-password"
            enterkeyhint="done"
            required
            :aria-invalid="!!fields.confirmation"
            aria-describedby="registration-confirmation-help"
            @input="delete fields.confirmation"
          />
          <small
            id="registration-confirmation-help"
            :class="{ 'field-error': fields.confirmation }"
            :role="fields.confirmation ? 'alert' : undefined"
            >{{ fields.confirmation ? t('onboarding.' + fields.confirmation) : '\u00a0' }}</small
          >
        </div>
      </template>

      <LoadingButton type="submit" class="button primary" :busy="busy">{{
        t('onboarding.' + (providerEmail ? 'continue' : 'signUp'))
      }}</LoadingButton>
      <SocialSignIn v-if="!providerEmail && !invitationToken" :readiness="readiness" />
      <p class="registration-trial muted">{{ t('onboarding.trial') }}</p>
    </form>
    <p v-if="error && !readiness" role="alert" class="field-error">{{ error }}</p>
    <RouterLink class="registration-login" to="/admin/login">{{
      t('onboarding.login')
    }}</RouterLink>
  </section>
</template>
<style scoped>
.registration-page {
  max-width: 520px;
  width: calc(100% - 32px);
  margin: 24px auto;
  display: grid;
  gap: 20px;
  padding: 24px;
}
header p {
  min-height: 2.9em;
}
header {
  display: grid;
  gap: 8px;
}
h1 {
  font-size: 1.65rem;
  line-height: 1.2;
  letter-spacing: -0.025em;
  margin: 0;
}
p {
  margin: 0;
  line-height: 1.45;
}
.registration-form {
  display: grid;
  gap: 12px;
  min-width: 0;
}
.registration-field {
  display: grid;
  gap: 6px;
  min-width: 0;
}
label {
  font-size: 0.9rem;
  font-weight: 500;
}
small {
  min-height: 1.2em;
  font-size: 0.8rem;
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
input[aria-invalid='true'] {
  border-color: var(--invalid-border);
}

.registration-form > .button {
  min-height: 44px;
}
.registration-trial {
  font-size: 0.8rem;
  text-align: center;
  padding-top: 4px;
}
.registration-login {
  text-align: center;
  font-size: 0.9rem;
}
.registration-closed {
  padding: 16px 0;
  border-top: 1px solid var(--border);
  color: var(--text-subtle);
}
.closed-icon {
  color: var(--link);
  margin-bottom: 8px;
}
@media (max-width: 520px) {
  .registration-page {
    margin: 20px auto;
    padding: 24px 20px;
    gap: 16px;
  }
}
</style>
