<script setup>
import AppFooter from '../components/AppFooter.vue'
import BrandLogo from '../components/PortalBrandLogo.vue'
import { computed, ref, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useWorkspaceAppearance } from '../services/workspaceAppearance.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { useProjectStore } from '../stores/project.js'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import { api } from '../api/client.js'
import { toast } from '../services/toast.js'
import { useWorkspaceBrandingStore } from '../stores/workspaceBranding.js'
import { confirmDeveloperLeave } from '../utils/developerEditor.js'
import { attachDrawerSwipe } from '../utils/drawerSwipe.js'
import { createAdminDrawer } from '../utils/adminDrawer.js'
import { t, formatApiError } from '../i18n/index.js'
import TaskHeaderNavigation from '../components/TaskHeaderNavigation.vue'
import TaskNavigator from '../components/TaskNavigator.vue'
import Breadcrumbs from '../components/Breadcrumbs.vue'
import ProjectSidebar from '../components/ProjectSidebar.vue'
import ProjectTrash from '../components/ProjectTrash.vue'
import { useTaskWorkspaceStore } from '../stores/taskWorkspace.js'
import TelegramPrompt from '../components/TelegramPrompt.vue'
import AdminSidebar from '../components/AdminSidebar.vue'
import AppIcon from '../components/AppIcon.vue'
import LanguageSwitcher from '../components/LanguageSwitcher.vue'
import LoadingOverlay from '../components/LoadingOverlay.vue'
import ThemeSwitcher from '../components/ThemeSwitcher.vue'
const route = useRoute(),
  router = useRouter(),
  nav = useAdminNavigationStore(),
  store = useProjectStore(),
  workspace = useTaskWorkspaceStore()
const { brand: activeBrand } = useWorkspaceAppearance()
const project = computed(() => nav.projectForRoute(route.params.uuid))
const trash = ref(null)
const trashOpening = ref(false)
const trashOpen = computed(() => !!trash.value?.open)
const menu = ref(null),
  dialog = ref(null),
  loggingOut = ref(false)
const drawer = createAdminDrawer({ dialog: () => dialog.value, trigger: () => menu.value })
const drawerOpen = drawer.open
const task = computed(() => route.meta.taskDetail && store.findTask(route.params.id))
let disposeSwipe
let breakpoint
function resize() {
  if (!route.meta.taskDetail && breakpoint?.matches && drawerOpen.value) drawer.close(false)
}
onMounted(() => {
  breakpoint = window.matchMedia?.('(min-width: 961px)')
  breakpoint?.addEventListener('change', resize)
  disposeSwipe = attachDrawerSwipe({
    mobile: () => breakpoint?.matches === false,
    isOpen: () => drawerOpen.value,
    canOpen: () => !route.meta.taskDetail && nav.ready,
    open: showMenu,
    close: closeMenu,
    dialog: () => dialog.value,
  })
})
onUnmounted(() => {
  disposeSwipe?.()
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
async function openTrash() {
  const id = project.value?.id
  if (!id || trashOpening.value) return
  trashOpening.value = true
  closeMenu(false)
  try {
    if (store.project.id !== id) {
      if (!(await store.loadApiProject(id, true, { skipAdmin: true }))) return
    }
    if (project.value?.id !== id) return
    await nextTick()
    await trash.value?.show()
  } catch (cause) {
    toast.error(() => formatApiError(cause))
    if (cause.code === 'project_permission_denied') {
      await router.replace(`/admin/projects/${id}/forbidden`)
    } else if ([401, 403, 404].includes(cause.status)) {
      nav.removeProject(id)
      useProjectManagementStore().clear()
      store.clearApiState()
      await router.replace(cause.status === 401 ? '/admin/login' : '/admin/unavailable')
    }
  } finally {
    trashOpening.value = false
  }
}
async function retry() {
  try {
    await nav.bootstrap(api.request, true)
    await router.replace('/admin')
  } catch (cause) {
    toast.error(() => formatApiError(cause))
  }
}
async function logout() {
  if (
    loggingOut.value ||
    !confirmDeveloperLeave(store) ||
    !useWorkspaceBrandingStore().confirmLeave()
  )
    return
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
  <div
    class="admin-shell"
    :class="{
      'is-task-workspace': route.meta.taskDetail,
      'task-workspace-shell': route.meta.taskDetail,
      'task-navigation-hidden': route.meta.taskDetail && workspace.hidden,
    }"
  >
    <TaskNavigator v-if="task" :task="task" />
    <aside v-if="!route.meta.taskDetail" class="admin-sidebar">
      <AdminSidebar
        :busy="loggingOut"
        :trash-busy="trashOpening"
        :trash-open="trashOpen"
        @trash="openTrash"
        @logout="logout"
      >
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
          <BrandLogo compact />
        </RouterLink>
        <span v-if="!route.meta.taskDetail" class="admin-toolbar-workspace">{{
          activeBrand?.companyName || nav.workspace?.name
        }}</span>
        <div class="admin-toolbar-controls">
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
        <template v-else-if="nav.ready">
          <TelegramPrompt
            v-if="!route.path.includes('/task/') && route.path !== '/admin/unavailable'"
          />
          <Breadcrumbs />
          <RouterView />
        </template>
        <LoadingOverlay v-else active />
      </main>
      <AppFooter />
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
        <ProjectSidebar
          v-if="route.meta.taskDetail && project"
          :project="project"
          task
          :busy="loggingOut"
          :trash-busy="trashOpening"
          :trash-open="trashOpen"
          @navigate="closeMenu(false)"
          @trash="openTrash"
          @logout="logout"
        />
        <div v-else-if="!route.meta.taskDetail">
          <AdminSidebar
            :busy="loggingOut"
            :trash-busy="trashOpening"
            :trash-open="trashOpen"
            @navigate="closeMenu(false)"
            @trash="openTrash"
            @logout="logout"
          >
            <template #logout
              ><button class="text-button" :disabled="loggingOut" @click="logout">
                {{ t('ui.signOut') }}
              </button></template
            >
          </AdminSidebar>
        </div>
      </div>
    </dialog>
    <ProjectTrash v-if="project" ref="trash" :trigger="false" />
  </div>
</template>
