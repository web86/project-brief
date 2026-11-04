<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import LoadingButton from '../components/LoadingButton.vue'
const approvalLabels = {
  missing: 'approvalMissing',
  approved: 'approvalApproved',
  expired: 'approvalExpired',
  stopped: 'approvalStopped',
  stale: 'approvalStale',
}
const state = ref(null),
  busy = ref(false),
  error = ref('')
async function load() {
  busy.value = true
  error.value = ''
  try {
    state.value = (await api.request('/api/platform/registration-diagnostics')).data
  } catch (e) {
    error.value = formatApiError(e)
  } finally {
    busy.value = false
  }
}
onMounted(load)
</script>
<template>
  <section class="platform-page registration-diagnostics">
    <h1>{{ t('onboarding.diagnostics') }}</h1>
    <p v-if="error" role="alert">{{ error }}</p>
    <LoadingButton class="button secondary" :busy="busy" @click="load">{{
      t('onboarding.refresh')
    }}</LoadingButton>
    <dl v-if="state">
      <div>
        <dt>{{ t('onboarding.registrationStatus') }}</dt>
        <dd>{{ t('onboarding.' + (state.publicRegistration ? 'enabled' : 'disabled')) }}</dd>
      </div>
      <div>
        <dt>{{ t('onboarding.activationStatus') }}</dt>
        <dd>{{ t('onboarding.' + (state.trialActivation ? 'yes' : 'disabled')) }}</dd>
      </div>
      <div>
        <dt>{{ t('onboarding.emailReady') }}</dt>
        <dd>{{ t('onboarding.' + (state.emailReady ? 'yes' : 'no')) }}</dd>
      </div>
      <template v-if="state.canary">
        <div v-for="key in ['studioVersion', 'basicVersion', 'policyVersion']" :key="key">
          <dt>{{ t('onboarding.' + key) }}</dt>
          <dd>{{ state.canary[key] ?? t('onboarding.notPublished') }}</dd>
        </div>
        <div>
          <dt>{{ t('onboarding.canaryConfiguration') }}</dt>
          <dd>{{ t('onboarding.' + (state.canary.configurationReady ? 'yes' : 'no')) }}</dd>
        </div>
        <div>
          <dt>{{ t('onboarding.retention') }}</dt>
          <dd>{{ state.canary.retentionDays ?? t('onboarding.no') }}</dd>
        </div>
        <div>
          <dt>{{ t('onboarding.pendingVerifiedClaims') }}</dt>
          <dd>{{ state.canary.pendingVerifiedClaims }}</dd>
        </div>
        <div>
          <dt>{{ t('onboarding.operatorApproval') }}</dt>
          <dd>{{ t('onboarding.' + approvalLabels[state.canary.operatorApproval]) }}</dd>
        </div>
        <div>
          <dt>{{ t('onboarding.approvedPendingClaims') }}</dt>
          <dd>{{ state.canary.approvedPendingClaims }}</dd>
        </div>
        <div>
          <dt>{{ t('onboarding.canaryAdmission') }}</dt>
          <dd>
            {{ t('onboarding.' + (state.canary.admissionEnabled ? 'switchOn' : 'disabled')) }}
          </dd>
        </div>
      </template>
      <div v-for="(ready, provider) in state.providers" :key="provider">
        <dt>{{ t('onboarding.' + provider) }}</dt>
        <dd>{{ t('onboarding.' + (ready ? 'yes' : 'no')) }}</dd>
      </div>
      <div>
        <dt>{{ t('onboarding.pendingCount') }}</dt>
        <dd>{{ state.pendingVerifications }}</dd>
      </div>
      <div>
        <dt>{{ t('onboarding.verifiedCount') }}</dt>
        <dd>{{ state.verifiedUsers }}</dd>
      </div>
    </dl>
    <details v-if="state">
      <summary>{{ t('onboarding.claims') }}</summary>
      <dl>
        <div v-for="(count, key) in state.claimStates" :key="key">
          <dt>{{ key }}</dt>
          <dd>{{ count }}</dd>
        </div>
      </dl>
    </details>
    <details v-if="state">
      <summary>{{ t('onboarding.reasons') }}</summary>
      <ul>
        <li v-for="code in state.canary?.reasonCodes" :key="code">
          <code>{{ code }}</code>
        </li>
        <li v-for="(count, code) in state.claimReasons" :key="code">
          <code>{{ code }}</code> · {{ count }}
        </li>
      </ul>
    </details>
  </section>
</template>
<style scoped>
.registration-diagnostics {
  max-width: 1000px;
  display: grid;
  gap: 20px;
}
dl {
  display: grid;
  gap: 0;
}
dl > div {
  display: flex;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  padding: 14px 0;
  border-bottom: 1px solid var(--border);
}
dd {
  margin: 0;
}
code {
  overflow-wrap: anywhere;
}
summary {
  cursor: pointer;
}
</style>
