<script setup>
import BrandLogo from './PortalBrandLogo.vue'
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import { useProjectStore } from '../stores/project.js'
import { useTaskWorkspaceStore } from '../stores/taskWorkspace.js'
import { createAdminDrawer } from '../utils/adminDrawer.js'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
const props = defineProps({ task: { type: Object, required: true } })
const store = useProjectStore(),
  workspace = useTaskWorkspaceStore(),
  route = useRoute()
const dialog = ref(null)
const drawer = createAdminDrawer({
  dialog: () => dialog.value,
  trigger: () => globalThis.document?.getElementById('task-navigation-trigger'),
})
const expanded = (id) => !!workspace.expanded[store.project.id]?.[id]
const groups = computed(() =>
  store.sectionOptions.map((section) => ({ ...section, tasks: store.sectionTasks(section.id) })),
)
let breakpoint
function resize() {
  workspace.mobile = !!breakpoint?.matches
  if (!workspace.mobile && workspace.panel === 'tasks') {
    workspace.closePanel()
    drawer.close(false)
    nextTick(() => globalThis.document?.querySelector('.task-header-back')?.focus())
  }
}
async function hide() {
  workspace.hideTasks()
  await nextTick()
  globalThis.document?.getElementById('task-navigation-trigger')?.focus()
}
function close() {
  workspace.closePanel()
  drawer.close()
}
function keydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    close()
  } else drawer.keydown(event)
}
function backdrop(event) {
  if (event.target === dialog.value) close()
}
watch(
  () => [store.project.id, props.task.id, props.task.sectionId],
  ([id, taskId, sectionId]) => workspace.revealSection(id, sectionId),
  { immediate: true },
)
watch(
  () => route.fullPath,
  () => {
    workspace.closePanel()
    drawer.close(false)
  },
)
watch(
  () => workspace.panel,
  async (panel) => {
    if (panel !== 'tasks') {
      drawer.close(false)
      return
    }
    await nextTick()
    if (workspace.panel === 'tasks' && workspace.mobile) drawer.show()
  },
)
onMounted(() => {
  breakpoint = globalThis.matchMedia?.('(max-width: 960px)')
  resize()
  breakpoint?.addEventListener('change', resize)
})
onUnmounted(() => {
  breakpoint?.removeEventListener('change', resize)
  workspace.closePanel()
  drawer.close(false)
})
</script>
<template>
  <aside class="task-navigation" :class="{ 'is-hidden': workspace.hidden }">
    <Teleport to="#task-navigation-drawer-body" :disabled="!workspace.mobile" defer>
      <nav
        id="project-task-navigation"
        class="task-navigation-content"
        :aria-label="t('taskWorkspace.tasks')"
      >
        <RouterLink
          :to="store.isDeveloper ? '/admin' : store.projectPath"
          class="brand task-navigation-brand"
          :aria-label="t('ui.projectBriefOpenTheProject')"
        >
          <BrandLogo />
        </RouterLink>
        <div class="task-navigation-heading">
          <h2>{{ t('taskWorkspace.tasks') }}</h2>
          <button
            class="icon-button"
            :aria-label="
              t(workspace.mobile ? 'taskWorkspace.closeTasks' : 'taskWorkspace.hideTasks')
            "
            @click="workspace.mobile ? close() : hide()"
          >
            <AppIcon :name="workspace.mobile ? 'close' : 'panel'" :size="18" />
          </button>
        </div>
        <p class="task-navigation-project">{{ store.project.name }}</p>
        <div class="task-navigation-sections">
          <section v-for="section in groups" :key="section.id" class="task-navigation-section">
            <button
              class="task-section-toggle"
              :aria-expanded="expanded(section.id)"
              :aria-controls="'task-navigation-' + section.id"
              @click="workspace.toggleSection(store.project.id, section.id)"
            >
              <AppIcon name="chevron" :size="14" :class="{ 'is-expanded': expanded(section.id) }" />
              <span>{{ section.name }}</span
              ><small>{{ section.tasks.length }}</small>
            </button>
            <ul
              v-show="expanded(section.id)"
              :id="'task-navigation-' + section.id"
              class="task-navigation-list"
            >
              <li v-for="item in section.tasks" :key="item.id">
                <RouterLink
                  :to="store.taskPath(item.id)"
                  :aria-current="item.id === task.id ? 'page' : undefined"
                  :title="item.title"
                >
                  <span class="task-navigation-number">{{ store.numberForTask(item) }}</span
                  ><span>{{ item.title }}</span>
                </RouterLink>
              </li>
              <li v-if="!section.tasks.length" class="small muted task-navigation-empty">
                {{ t('flow.emptySection') }}
              </li>
            </ul>
          </section>
        </div>
      </nav>
    </Teleport>
  </aside>
  <dialog
    id="task-navigation-drawer"
    ref="dialog"
    class="admin-drawer task-navigation-drawer"
    :aria-label="t('taskWorkspace.tasks')"
    @cancel.prevent="close"
    @keydown="keydown"
    @click="backdrop"
  >
    <div id="task-navigation-drawer-body" />
  </dialog>
</template>
