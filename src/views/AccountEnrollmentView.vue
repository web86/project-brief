<script setup>
import { useRouter } from 'vue-router'
import AccountSecurityPanel from '../components/AccountSecurityPanel.vue'
import { api } from '../api/client.js'
import { t } from '../i18n/index.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
const router = useRouter()
async function ready() {
  useAdminNavigationStore().clear()
  await router.replace('/admin')
}
async function logout() {
  await api.request('/api/admin/logout', { method: 'POST' })
  api.resetCsrf()
  useAdminNavigationStore().clear()
  await router.replace('/admin/login')
}
</script>
<template>
  <div class="account-page">
    <h1>{{ t('security.title') }}</h1>
    <AccountSecurityPanel onboarding @ready="ready" /><button class="text-button" @click="logout">
      {{ t('account.logout') }}
    </button>
  </div>
</template>
