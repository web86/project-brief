<script setup>
import { useRouter } from 'vue-router'
import { useProjectStore } from '../stores/project.js'
import { t, formatApiError } from '../i18n/index.js'
const router = useRouter()
const store = useProjectStore()
</script>
<template>
  <section class="surface app-entry">
    <div class="brand-mark" aria-hidden="true">p<span class="brand-dot">.</span></div>
    <h1>ProjectBrief</h1>
    <template v-if="store.apiError">
      <p role="alert">{{ formatApiError(store.apiError) }}</p>
      <button class="button primary" @click="router.replace(`/app?retry=${Date.now()}`)">
        {{ t('notifications.retry') }}
      </button>
    </template>
    <template v-else>
      <h2>{{ t('pwa.noAccess') }}</h2>
      <p>{{ t('pwa.openLink') }}</p>
    </template>
    <RouterLink to="/admin/login" class="text-button">{{ t('pwa.adminLogin') }}</RouterLink>
  </section>
</template>
