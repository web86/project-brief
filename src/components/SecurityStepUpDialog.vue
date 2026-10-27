<script setup>
import { computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute } from 'vue-router'
import BaseDialog from './BaseDialog.vue'
import SecurityConfirmation from './SecurityConfirmation.vue'
import SecurityError from './SecurityError.vue'
import LoadingButton from './LoadingButton.vue'
import {
  stepUp,
  stepUpRequest,
  stepUpState,
  isSecurityConfirmationScreen,
} from '../services/stepUp.js'
import { api } from '../api/client.js'
import { passkeyCeremony, passkeysSupported } from '../services/accountSecurity.js'
import { useProjectStore } from '../stores/project'
import { toast } from '../services/toast.js'
import { t, formatApiError } from '../i18n/index.js'
const route = useRoute(),
  store = useProjectStore()
stepUp.configure({
  status: () => api.request('/api/security/status'),
  options: () => api.request('/api/security/passkey/confirm/options', { securityRetry: false }),
  passkey: (options) => passkeyCeremony('confirm', undefined, options),
  supported: passkeysSupported,
  activated: () => navigator.userActivation?.isActive !== false,
  error: (cause) => toast.error(() => formatApiError(cause)),
  changed: () => toast.info(() => t('security.contextChanged')),
  cancelled: () => toast.info(() => t('security.confirmationCancelled')),
})
const available = computed(() => {
  const status = stepUpState.value?.status
  return status?.signedIn && (status.totp || !status.owner)
})
const enabled = () =>
  store.apiMode && route.meta.adminShell && isSecurityConfirmationScreen(route.path)
function warm() {
  if (enabled() && document.visibilityState !== 'hidden') stepUp.prepare().catch(() => {})
}
watch(
  () => route.path,
  () => {
    stepUp.leave()
    warm()
  },
  { immediate: true },
)
let timer
onMounted(() => {
  timer = setInterval(warm, 60000)
  window.addEventListener('focus', warm)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  window.removeEventListener('focus', warm)
  stepUp.invalidate(true)
})
</script>
<template>
  <BaseDialog
    :open="!!stepUpRequest && stepUpState?.mode !== 'native'"
    :title="t(stepUpState?.mode === 'retry' ? 'security.confirmUnavailable' : 'security.confirm')"
    id="security-step-up-title"
    @close="stepUp.cancel()"
  >
    <template v-if="stepUpRequest">
      <template v-if="stepUpState?.mode === 'retry'">
        <p>{{ t('security.passkeyUnavailable') }}</p>
        <SecurityError :error="stepUpState.error" />
        <div class="security-actions">
          <LoadingButton
            v-if="passkeysSupported()"
            class="button primary"
            :busy="stepUpState.busy"
            :disabled="stepUpState.busy"
            @click="stepUp.retry()"
            >{{ t('security.retryPasskey') }}</LoadingButton
          >
          <button v-if="available" class="button secondary" @click="stepUp.alternative()">
            {{ t('security.otherMethod') }}
          </button>
        </div>
      </template>
      <SecurityConfirmation
        v-else-if="stepUpState?.mode === 'alternative' && available"
        compact
        :initial-status="stepUpState.status"
        @confirmed="stepUp.accept"
      />
      <p v-else-if="!stepUpState?.status?.signedIn">
        {{ t('ui.yourSessionHasEndedSignInAgainOr') }}
      </p>
      <p v-else>{{ t('security.finishTotp') }}</p>
      <button class="text-button" @click="stepUp.cancel()">{{ t('account.cancel') }}</button>
    </template>
  </BaseDialog>
</template>
