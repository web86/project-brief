<script setup>
import { ref, watch, nextTick, onUnmounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useProjectStore } from '../stores/project.js'
import { useTaskWorkspaceStore } from '../stores/taskWorkspace.js'
import { createAdminDrawer } from '../utils/adminDrawer.js'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
import UnreadBadge from './UnreadBadge.vue'
const route = useRoute(),
  store = useProjectStore(),
  workspace = useTaskWorkspaceStore()
const trigger = ref(null),
  dialog = ref(null)
const drawer = createAdminDrawer({ dialog: () => dialog.value, trigger: () => trigger.value })
const open = drawer.open
const unread = computed(() => store.project.unreadCommentCount || 0)
const links = computed(() => [
  { path: store.projectPath, label: t('clientWorkspace.ideas'), icon: 'grid' },
  ...['discussions', 'notifications', 'export'].map((section) => ({
    path: `${store.projectPath}/${section}`,
    label: t('nav.' + section),
    icon: { discussions: 'comment', notifications: 'bell', export: 'file' }[section],
    unread: section === 'discussions' ? unread.value : 0,
  })),
])
function close(restore = true) {
  workspace.closePanel()
  drawer.close(restore)
}
function keydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    close()
  } else drawer.keydown(event)
}
watch(
  () => workspace.panel,
  async (panel) => {
    if (panel !== 'client') {
      drawer.close(false)
      return
    }
    await nextTick()
    if (workspace.panel === 'client') drawer.show()
  },
)
watch(
  () => route.fullPath,
  () => close(false),
)
onUnmounted(() => close(false))
</script>
<template>
  <button
    ref="trigger"
    type="button"
    class="icon-button client-menu-trigger"
    :aria-label="t('nav.openMenu')"
    :aria-expanded="open"
    aria-controls="client-drawer"
    @click="workspace.openClient()"
  >
    <AppIcon name="menu" :size="22" />
  </button>
  <Teleport to="body" defer>
    <dialog
      ref="dialog"
      id="client-drawer"
      class="admin-drawer task-management-drawer client-drawer"
      :aria-label="t('clientWorkspace.menu')"
      @keydown="keydown"
      @cancel.prevent="close()"
      @click="
        (event) => {
          if (event.target === dialog) close()
        }
      "
    >
      <button
        type="button"
        class="icon-button drawer-close"
        :aria-label="t('nav.closeMenu')"
        @click="close()"
      >
        <AppIcon name="close" />
      </button>
      <div class="task-management-content client-drawer-content">
        <h2>{{ t('clientWorkspace.menu') }}</h2>
        <p class="muted">{{ store.project.name }}</p>
        <nav class="project-panel-nav" :aria-label="t('clientWorkspace.menu')">
          <RouterLink
            v-for="link in links"
            :key="link.path"
            :to="link.path"
            :class="{ active: route.path === link.path }"
            :aria-current="route.path === link.path ? 'page' : undefined"
            @click="close(false)"
            ><AppIcon :name="link.icon" :size="19" /><span>{{ link.label }}</span
            ><UnreadBadge v-if="link.unread" :count="link.unread"
          /></RouterLink>
        </nav>
        <footer class="sidebar-account">
          <strong>{{ store.currentClient?.name }}</strong
          ><span>{{ t('clientWorkspace.role') }}</span>
        </footer>
      </div>
    </dialog>
  </Teleport>
</template>
