<script setup>
import { useRoute } from 'vue-router'
import { t } from '../i18n/index.js'
import AdminAccountView from './AdminAccountView.vue'
import AdminShell from './AdminShell.vue'
import AppIcon from '../components/AppIcon.vue'
const route = useRoute()
</script>
<template>
  <section v-if="route.meta.preparedOwner" class="personal-security-page">
    <RouterLink class="security-back" to="/admin/onboarding"
      ><AppIcon name="arrow-left" :size="18" />{{ t('onboarding.accountStatus') }}</RouterLink
    >
    <AdminAccountView standalone-security prepared-owner />
  </section>
  <AdminShell v-else>
    <AdminAccountView />
  </AdminShell>
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
