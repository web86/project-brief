<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import AdminAccountView from './AdminAccountView.vue'
import AppIcon from '../components/AppIcon.vue'
const status = ref(null),
  error = ref('')
onMounted(async () => {
  try {
    status.value = await api.request('/api/security/status')
  } catch (cause) {
    error.value = formatApiError(cause)
  }
})
</script>
<template>
  <section class="personal-security-page">
    <RouterLink v-if="status?.onboardingNext" class="security-back" to="/admin/onboarding"
      ><AppIcon name="arrow-left" :size="18" />{{ t('onboarding.accountStatus') }}</RouterLink
    >
    <p v-if="error" role="alert">{{ error }}</p>
    <AdminAccountView
      v-if="status?.signedIn"
      standalone-security
      :prepared-owner="!!status.onboardingNext"
    />
  </section>
</template>
<style scoped>
.personal-security-page {
  max-width: 880px;
  width: 100%;
  margin: 24px auto;
  padding: 0 16px;
}
.security-back {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 16px;
}
.personal-security-page :deep(.account-page) {
  padding: 0;
  margin: 0;
  max-width: none;
}
</style>
