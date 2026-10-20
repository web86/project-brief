<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { useProjectStore } from '../stores/project.js'
import { api } from '../api/client.js'
import { toast } from '../services/toast.js'
import { confirmDeveloperLeave } from '../utils/developerEditor.js'
import { createAdminDrawer } from '../utils/adminDrawer.js'
import { t, formatApiError } from '../i18n/index.js'
import TaskHeaderNavigation from '../components/TaskHeaderNavigation.vue'
import ProjectNavigation from '../components/ProjectNavigation.vue'
import { useTaskWorkspaceStore } from '../stores/taskWorkspace.js'
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
  store = useProjectStore(),
  workspace = useTaskWorkspaceStore()
const menu = ref(null),
  dialog = ref(null),
  loggingOut = ref(false)
const drawer = createAdminDrawer({ dialog: () => dialog.value, trigger: () => menu.value })
const drawerOpen = drawer.open
let breakpoint
function resize() {
  if (!route.meta.taskDetail && breakpoint?.matches && drawerOpen.value) drawer.close(false)
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
  () => {
    workspace.closePanel()
    drawer.close(false)
  },
)
function showMenu() {
  if (route.meta.taskDetail) workspace.openAdmin()
  else drawer.show()
}
function closeMenu(restore = true) {
  workspace.closePanel()
  drawer.close(restore)
}
function drawerKeydown(event) {
  if (route.meta.taskDetail && event.key === 'Escape') {
    event.preventDefault()
    closeMenu()
  } else drawer.keydown(event)
}
function drawerBackdrop(event) {
  if (event.target === dialog.value) closeMenu()
}
watch(
  () => workspace.panel,
  async (panel) => {
    if (!route.meta.taskDetail) return
    if (panel !== 'admin') {
      drawer.close(false)
      return
    }
    await nextTick()
    if (workspace.panel === 'admin' && route.meta.taskDetail) drawer.show()
  },
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
  <div class="admin-shell" :class="{ 'is-task-workspace': route.meta.taskDetail }">
    <aside v-if="!route.meta.taskDetail" class="admin-sidebar">
      <AdminSidebar>
        <template #logout
          ><button class="text-button" :disabled="loggingOut" @click="logout">
            {{ t('ui.signOut') }}
          </button></template
        >
      </AdminSidebar>
    </aside>
    <div class="admin-content">
      <header class="admin-toolbar" :class="{ 'task-topbar': route.meta.taskDetail }">
        <TaskHeaderNavigation v-if="route.meta.taskDetail" />
        <button
          v-if="!route.meta.taskDetail"
          ref="menu"
          class="icon-button admin-menu-trigger"
          :aria-label="t('nav.openMenu')"
          :aria-expanded="drawerOpen"
          aria-controls="admin-drawer"
          @click="showMenu"
        >
          <AppIcon name="menu" />
        </button>
        <RouterLink
          v-if="!route.meta.taskDetail"
          to="/admin"
          class="brand mobile-toolbar-brand"
          :aria-label="t('ui.projectBriefOpenTheProject')"
        >
          <span class="brand-mark">p<span class="brand-dot">.</span></span>
        </RouterLink>
        <span v-if="!route.meta.taskDetail" class="admin-toolbar-workspace">{{
          nav.workspace?.name
        }}</span>
        <div class="admin-toolbar-controls">
          <ResponsivePushControls
            v-if="store.admin"
            :key="store.admin.email"
            persona="admin"
            footer="#admin-push-footer"
          />
          <div class="appearance-controls"><ThemeSwitcher /><LanguageSwitcher /></div>
          <button
            v-if="route.meta.taskDetail"
            ref="menu"
            class="icon-button task-admin-trigger"
            :aria-label="t('taskWorkspace.manageProject')"
            :aria-expanded="drawerOpen"
            aria-controls="admin-drawer"
            @click="showMenu"
          >
            <AppIcon name="menu" />
          </button>
        </div>
      </header>
      <main
        id="main-content"
        class="admin-main"
        :class="{ 'task-admin-main': route.meta.taskDetail }"
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
      :class="{ 'task-management-drawer': route.meta.taskDetail }"
      :aria-label="t(route.meta.taskDetail ? 'taskWorkspace.manageProject' : 'nav.menu')"
      @cancel.prevent="closeMenu()"
      @keydown="drawerKeydown"
      @click="drawerBackdrop"
    >
      <button
        class="icon-button drawer-close"
        :aria-label="t('nav.closeMenu')"
        @click="closeMenu()"
      >
        <AppIcon name="close" />
      </button>
      <div
        class="admin-drawer-content"
        :class="{ 'task-management-content': route.meta.taskDetail }"
      >
        <section v-if="route.meta.taskDetail" class="task-project-management">
          <h2>{{ t('taskWorkspace.manageProject') }}</h2>
          <p class="small muted">{{ store.project.name }}</p>
          <ProjectNavigation vertical />
        </section>
        <div :class="{ 'task-global-management': route.meta.taskDetail }">
          <h3 v-if="route.meta.taskDetail">{{ t('taskWorkspace.workArea') }}</h3>
          <AdminSidebar :compact="!!route.meta.taskDetail" @navigate="closeMenu(false)">
            <template #logout
              ><button class="text-button" :disabled="loggingOut" @click="logout">
                {{ t('ui.signOut') }}
              </button></template
            >
          </AdminSidebar>
        </div>
      </div>
    </dialog>
  </div>
</template>
