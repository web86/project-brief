<script setup>
import { useRouter } from 'vue-router'
import AccountSecurityPanel from '../components/AccountSecurityPanel.vue'
import { api } from '../api/client.js'
import { t } from '../i18n/index.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
const router = useRouter()
async function ready() {
  useAdminNavigationStore().clear()
  const session = await api.request('/api/session')
  await router.replace(session.type === 'platform' ? '/platform' : '/admin')
}
async function logout() {
  await api.request('/api/admin/logout', { method: 'POST' })
  api.resetCsrf()
  useAdminNavigationStore().clear()
  await router.replace('/admin/login')
}
</script>
<template>
  <div class="account-page enrollment-page">
    <h1>{{ t('team95.accountProtection') }}</h1>
    <p class="muted onboarding-intro">{{ t('team95.securityIntro') }}</p>
    <AccountSecurityPanel onboarding @ready="ready" /><button class="text-button" @click="logout">
      {{ t('account.logout') }}
    </button>
  </div>
</template>

<style scoped>
.enrollment-page {
  max-width: 940px;
}
.enrollment-page > .text-button {
  margin-top: 1rem;
}
.onboarding-intro {
  margin: 0.75rem 0 1.5rem;
}
</style>
