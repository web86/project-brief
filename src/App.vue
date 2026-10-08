<script setup>
import PushControls from './components/PushControls.vue'
import ToastViewport from './components/ToastViewport.vue'
import { toast } from './services/toast.js'
import { confirmDeveloperLeave } from './utils/developerEditor.js'
import { t, locale, formatApiError } from './i18n/index.js'

import { nextTick, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useProjectStore } from './stores/project'
import LanguageSwitcher from './components/LanguageSwitcher.vue'
import LoadingOverlay from './components/LoadingOverlay.vue'
import ModeSwitcher from './components/ModeSwitcher.vue'
import { api } from './api/client'
import AppIcon from './components/AppIcon.vue'
const store = useProjectStore()
const route = useRoute()
const router = useRouter()
async function logout() {
  if (!confirmDeveloperLeave(store)) return
  try {
    await api.request('/api/admin/logout', { method: 'POST' })
    api.resetCsrf()
    store.discardDeveloperChanges()
    store.admin = null
    store.currentClient = null
    store.tasks = []
    await router.push('/admin/login')
  } catch (error) {
    toast.error(() => formatApiError(error))
  }
}
watch(
  () => store.sessionLost,
  (lost) => {
    if (lost && !Object.keys(store.developerDrafts).length) {
      const destination = store.admin ? '/admin/login' : '/access-error?reason=session'
      store.admin = null
      store.tasks = []
      store.currentClient = null
      router.replace(destination)
    }
  },
)
store.loadFromStorage()
watch(
  () => store.apiError,
  (error) => {
    if (error && route.path !== '/app' && error.status !== 422 && !store.savingDeveloper)
      toast.error(() => formatApiError(error))
  },
)
watch(
  () => [route.fullPath, locale.value],
  () => {
    document.title = `${route.meta.title || t('ui.yourProject')} — Project Brief`
  },
  { immediate: true },
)
watch(
  () => route.fullPath,
  async () => {
    await nextTick()
    document.getElementById('main-content')?.focus({ preventScroll: true })
  },
)
</script>
<template>
  <a class="skip-link" href="#main-content">{{ t('ui.skipToContent') }}</a>
  <div class="app-shell">
    <header class="app-topbar">
      <div class="topbar-inner">
        <RouterLink
          :to="
            store.apiMode
              ? store.admin
                ? '/admin'
                : store.currentClient && store.project.id
                  ? store.projectPath
                  : '/app'
              : '/'
          "
          class="brand"
          :aria-label="t('ui.projectBriefOpenTheProject')"
          ><span class="brand-mark">p<span class="brand-dot">.</span></span
          ><span>project<span class="brand-light">brief</span></span></RouterLink
        ><ModeSwitcher v-if="!store.apiMode" />
        <nav v-else-if="store.admin" class="admin-nav" :aria-label="t('ui.administrator')">
          <RouterLink to="/admin">{{ t('ui.projects') }}</RouterLink
          ><button class="text-button" @click="logout">{{ t('ui.signOut') }}</button>
        </nav>
        <span v-else-if="store.currentClient" class="current-client-name">{{
          store.currentClient.name
        }}</span>
        <PushControls
          v-if="store.apiMode && (store.admin || store.currentClient)"
          :key="store.admin ? 'admin:' + store.admin.email : 'client:' + store.currentClient.id"
          :persona="store.admin ? 'admin' : 'client'"
        />
        <LanguageSwitcher />
      </div>
    </header>
    <LoadingOverlay :active="store.apiLoading" />
    <div class="workspace">
      <div v-if="store.storageError" class="storage-warning" role="alert">
        <AppIcon name="info" :size="22" />
        <div>
          <strong>{{
            store.storageBlocked
              ? t('ui.weCouldNotOpenTheSavedProject')
              : t('ui.weCouldNotSaveTheProject')
          }}</strong>
          <p>{{ formatApiError(store.storageError) }}</p>
        </div>
        <button
          v-if="!store.storageBlocked"
          class="button secondary"
          @click="store.saveToStorage()"
        >
          {{ t('ui.trySavingAgain') }}
        </button>
      </div>
      <main id="main-content" tabindex="-1"><RouterView /></main>
    </div>
  </div>
  <ToastViewport />
</template>
