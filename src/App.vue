<script setup>
import TaskHeaderNavigation from './components/TaskHeaderNavigation.vue'
import TaskNavigator from './components/TaskNavigator.vue'
import { useTaskWorkspaceStore } from './stores/taskWorkspace.js'
import ResponsivePushControls from './components/ResponsivePushControls.vue'
import MobilePushFooter from './components/MobilePushFooter.vue'
import ToastViewport from './components/ToastViewport.vue'
import { toast } from './services/toast.js'
import { confirmDeveloperLeave } from './utils/developerEditor.js'
import { t, locale, formatApiError } from './i18n/index.js'

import { computed, nextTick, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAdminNavigationStore } from './stores/adminNavigation.js'
import { useProjectStore } from './stores/project'
import LanguageSwitcher from './components/LanguageSwitcher.vue'
import ThemeSwitcher from './components/ThemeSwitcher.vue'
import LoadingOverlay from './components/LoadingOverlay.vue'
import ModeSwitcher from './components/ModeSwitcher.vue'
import { api } from './api/client'
import AppIcon from './components/AppIcon.vue'
const store = useProjectStore()
const route = useRoute()
const router = useRouter()
const workspace = useTaskWorkspaceStore()
const task = computed(() => route.meta.taskDetail && store.findTask(route.params.id))
const navigationPending = router.navigationPending
async function logout() {
  if (!confirmDeveloperLeave(store)) return
  try {
    await api.request('/api/admin/logout', { method: 'POST' })
    api.resetCsrf()
    useAdminNavigationStore().clear()
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
      useAdminNavigationStore().clear()
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
  <div :inert="navigationPending || undefined" :aria-busy="navigationPending">
    <a class="skip-link" href="#main-content">{{ t('ui.skipToContent') }}</a>
    <RouterView v-if="route.meta.adminShell" />
    <div
      v-else
      class="app-shell"
      :class="{
        'task-workspace-shell': route.meta.taskDetail,
        'task-navigation-hidden': route.meta.taskDetail && workspace.hidden,
      }"
    >
      <TaskNavigator v-if="task" :task="task" />
      <header class="app-topbar" :class="{ 'task-topbar': route.meta.taskDetail }">
        <div class="topbar-inner">
          <TaskHeaderNavigation v-if="route.meta.taskDetail" />
          <RouterLink
            v-else
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
            ><span class="brand-wordmark"
              >project<span class="brand-light">brief</span></span
            ></RouterLink
          ><ModeSwitcher v-if="!store.apiMode" />
          <span
            v-else-if="store.currentClient && !route.meta.taskDetail"
            class="current-client-name"
            >{{ store.currentClient.name }}</span
          >
          <ResponsivePushControls
            v-if="store.apiMode && (store.admin || store.currentClient)"
            :key="store.admin ? 'admin:' + store.admin.email : 'client:' + store.currentClient.id"
            :persona="store.admin ? 'admin' : 'client'"
            footer="#client-push-footer"
          />
          <div class="appearance-controls"><ThemeSwitcher /><LanguageSwitcher /></div>
        </div>
      </header>
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
        <MobilePushFooter
          v-if="store.apiMode && (store.admin || store.currentClient)"
          id="client-push-footer"
        />
      </div>
    </div>
  </div>
  <LoadingOverlay :active="navigationPending" class="navigation-loading" />
  <ToastViewport />
</template>
