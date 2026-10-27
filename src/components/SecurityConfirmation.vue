<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { passkeyCeremony, passkeysSupported } from '../services/accountSecurity.js'
import LoadingButton from './LoadingButton.vue'
import SecurityError from './SecurityError.vue'
import { toast } from '../services/toast.js'
const props = defineProps({ pending: Boolean })
const emit = defineEmits(['confirmed'])
const status = ref(null),
  password = ref(''),
  code = ref(''),
  recovery = ref(false),
  busy = ref(false),
  error = ref(null)
onMounted(async () => {
  try {
    status.value = await api.request('/api/security/status')
  } catch (cause) {
    error.value = cause
    toast.error(() => formatApiError(cause))
  }
})
function switchRecovery() {
  recovery.value = !recovery.value
  code.value = ''
}
async function confirm(passkey = false) {
  if (busy.value) return
  busy.value = true
  error.value = null
  try {
    const result = passkey
      ? await passkeyCeremony(status.value?.signedIn ? 'confirm' : 'login')
      : await api.request(
          props.pending && !status.value?.signedIn
            ? '/api/security/challenge'
            : '/api/security/confirm',
          {
            method: 'POST',
            body: {
              current_password: password.value,
              [recovery.value ? 'recovery_code' : 'code']: code.value || null,
            },
          },
        )
    api.resetCsrf()
    password.value = ''
    code.value = ''
    toast.success(() => t('security.identityConfirmed'))
    emit('confirmed', result)
  } catch (cause) {
    error.value = cause
    toast.error(() => formatApiError(cause))
  } finally {
    busy.value = false
  }
}
</script>
<template>
  <form class="admin-form" @submit.prevent="confirm()">
    <p>{{ t(pending ? 'security.challengeHelp' : 'security.stepUpHelp') }}</p>
    <div v-if="!pending || status?.signedIn" class="field">
      <label for="security-password">{{ t('account.currentPassword') }}</label>
      <input
        id="security-password"
        v-model="password"
        type="password"
        autocomplete="current-password"
        maxlength="1024"
        required
      />
    </div>
    <template v-if="pending || (status?.owner && status?.totp)">
      <div class="field">
        <label for="security-code">{{
          t(recovery ? 'security.recoveryCode' : 'security.code')
        }}</label>
        <input
          id="security-code"
          v-model="code"
          :inputmode="recovery ? 'text' : 'numeric'"
          :autocomplete="recovery ? 'off' : 'one-time-code'"
          :maxlength="recovery ? 100 : 6"
          :pattern="recovery ? undefined : '[0-9]{6}'"
          required
        />
      </div>
      <button class="text-button" type="button" @click="switchRecovery">
        {{ t(recovery ? 'security.useTotp' : 'security.useRecovery') }}
      </button>
    </template>
    <SecurityError :error="error" />
    <LoadingButton class="button primary" type="submit" :busy="busy" :disabled="busy">{{
      t('security.confirm')
    }}</LoadingButton>
    <button
      v-if="passkeysSupported()"
      class="button secondary"
      type="button"
      :disabled="busy"
      @click="confirm(true)"
    >
      {{ t('security.signInPasskey') }}
    </button>
  </form>
</template>
