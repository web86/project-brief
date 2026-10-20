<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { useProjectStore } from '../stores/project.js'
import { api } from '../api/client.js'
import { toast } from '../services/toast.js'
import { confirmDeveloperLeave } from '../utils/developerEditor.js'
import { createAdminDrawer } from '../utils/adminDrawer.js'
import { t, formatApiError } from '../i18n/index.js'
import AdminSidebar from '../components/AdminSidebar.vue'
import AppIcon from '../components/AppIcon.vue'
import ResponsivePushControls from '../components/ResponsivePushControls.vue'
import MobilePushFooter from '../components/MobilePushFooter.vue'
import LanguageSwitcher from '../components/LanguageSwitcher.vue'
import LoadingOverlay from '../components/LoadingOverlay.vue'
import ThemeSwitcher from '../components/ThemeSwitcher.vue'
const route = useRoute(),
  router = useRouter(),
  nav = useAdminNavigationStore(),
  store = useProjectStore()
const menu = ref(null),
  dialog = ref(null),
  loggingOut = ref(false)
const drawer = createAdminDrawer({ dialog: () => dialog.value, trigger: () => menu.value })
const drawerOpen = drawer.open
let breakpoint
function resize() {
  if (breakpoint?.matches && drawerOpen.value) drawer.close(false)
}
onMounted(() => {
  breakpoint = window.matchMedia?.('(min-width: 961px)')
  breakpoint?.addEventListener('change', resize)
})
onUnmounted(() => {
  breakpoint?.removeEventListener('change', resize)
  drawer.close(false)
})
watch(
  () => route.fullPath,
  () => drawer.close(false),
)
async function retry() {
  try {
    await nav.bootstrap(api.request, true)
    await router.replace('/admin')
  } catch (cause) {
    toast.error(() => formatApiError(cause))
  }
}
async function logout() {
  if (loggingOut.value || !confirmDeveloperLeave(store)) return
  loggingOut.value = true
  try {
    await api.request('/api/admin/logout', { method: 'POST' })
    api.resetCsrf()
    nav.clear()
    store.admin = null
    await router.push('/admin/login')
  } catch (cause) {
    toast.error(() => formatApiError(cause))
  } finally {
    loggingOut.value = false
  }
}
</script>
<template>
  <div class="admin-shell">
    <aside class="admin-sidebar">
      <AdminSidebar>
        <template #logout
          ><button class="text-button" :disabled="loggingOut" @click="logout">
            {{ t('ui.signOut') }}
          </button></template
        >
      </AdminSidebar>
    </aside>
    <div class="admin-content">
      <header class="admin-toolbar">
        <button
          ref="menu"
          class="icon-button admin-menu-trigger"
          :aria-label="t('nav.openMenu')"
          :aria-expanded="drawerOpen"
          aria-controls="admin-drawer"
          @click="drawer.show"
        >
          <AppIcon name="menu" />
        </button>
        <RouterLink
          to="/admin"
          class="brand mobile-toolbar-brand"
          :aria-label="t('ui.projectBriefOpenTheProject')"
        >
          <span class="brand-mark">p<span class="brand-dot">.</span></span>
        </RouterLink>
        <span class="admin-toolbar-workspace">{{ nav.workspace?.name }}</span>
        <div class="admin-toolbar-controls">
          <ResponsivePushControls
            v-if="store.admin"
            :key="store.admin.email"
            persona="admin"
            footer="#admin-push-footer"
          />
          <div class="appearance-controls"><ThemeSwitcher /><LanguageSwitcher /></div>
        </div>
      </header>
      <main
        id="main-content"
        class="admin-main"
        tabindex="-1"
        :aria-busy="nav.switching || nav.loading"
      >
        <p v-if="nav.switching" class="admin-switch-progress" role="status">
          {{ t('nav.switching') }}
        </p>
        <div v-else-if="nav.error && !nav.ready" class="surface navigation-error" role="alert">
          <p>{{ formatApiError(nav.error) }}</p>
          <button class="button secondary" @click="retry">{{ t('ui.tryAgain') }}</button>
        </div>
        <RouterView v-else-if="nav.ready" />
        <LoadingOverlay v-else active />
      </main>
      <MobilePushFooter v-if="store.admin" id="admin-push-footer" />
    </div>
    <dialog
      id="admin-drawer"
      ref="dialog"
      class="admin-drawer"
      :aria-label="t('nav.menu')"
      @cancel.prevent="drawer.close()"
      @keydown="drawer.keydown"
      @click="drawer.backdrop"
    >
      <button
        class="icon-button drawer-close"
        :aria-label="t('nav.closeMenu')"
        @click="drawer.close()"
      >
        <AppIcon name="close" />
      </button>
      <AdminSidebar @navigate="drawer.close(false)">
        <template #logout
          ><button class="text-button" :disabled="loggingOut" @click="logout">
            {{ t('ui.signOut') }}
          </button></template
        >
      </AdminSidebar>
    </dialog>
  </div>
</template>
