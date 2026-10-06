<script setup>
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
  await store.flushDeveloperData()
  try {
    await api.request('/api/admin/logout', { method: 'POST' })
    api.resetCsrf()
    store.admin = null
    store.currentClient = null
    store.tasks = []
    await router.push('/admin/login')
  } catch (error) {
    store.apiError = error
  }
}
watch(
  () => store.sessionLost,
  (lost) => {
    if (lost) {
      const destination = store.admin ? '/admin/login' : '/access-error?reason=session'
      store.admin = null
      store.tasks = []
      store.developerDrafts = {}
      store.currentClient = null
      router.replace(destination)
    }
  },
)
store.loadFromStorage()
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
                : store.project.id
                  ? store.projectPath
                  : '/admin/login'
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
      <div v-if="store.apiError" class="storage-warning" role="alert">
        <p>{{ formatApiError(store.apiError) }}</p>
        <button
          v-if="Object.keys(store.developerDrafts).length"
          class="button secondary"
          @click="store.flushDeveloperData()"
        >
          {{ t('ui.trySavingAgain') }}
        </button>
      </div>
      <p
        v-if="store.apiMode && Object.keys(store.developerDrafts).length"
        role="status"
        class="save-status"
      >
        {{
          store.savingDeveloper
            ? t('ui.savingChanges')
            : store.apiError
              ? t('ui.changesHaveNotBeenSavedYet')
              : t('ui.changesWillBeSavedAutomatically')
        }}
      </p>
      <main id="main-content" tabindex="-1"><RouterView /></main>
    </div>
  </div>
</template>
