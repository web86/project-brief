<script setup>
import { nextTick } from 'vue'
import { useProjectStore } from '../stores/project.js'
import { useTaskWorkspaceStore } from '../stores/taskWorkspace.js'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
const store = useProjectStore(),
  workspace = useTaskWorkspaceStore()
async function openTasks() {
  workspace.openTasks()
  if (!workspace.mobile) {
    await nextTick()
    globalThis.document
      ?.querySelector('#project-task-navigation .task-navigation-heading button')
      ?.focus({ preventScroll: true })
  }
}
</script>
<template>
  <div class="task-header-navigation">
    <RouterLink
      :to="store.projectPath"
      class="task-header-back"
      :aria-label="t('ui.backToTheProject2')"
    >
      <AppIcon name="arrow" :size="19" />
      <span class="task-back-full">{{ t('ui.backToTheProject2') }}</span>
      <span class="task-back-short" aria-hidden="true">{{ t('nav.project') }}</span>
    </RouterLink>
    <button
      id="task-navigation-trigger"
      class="icon-button task-navigation-trigger"
      :class="{ 'is-visible': workspace.hidden || workspace.mobile }"
      :aria-label="t('taskWorkspace.openTasks')"
      :aria-expanded="workspace.mobile ? workspace.panel === 'tasks' : !workspace.hidden"
      :aria-controls="workspace.mobile ? 'task-navigation-drawer' : 'project-task-navigation'"
      @click="openTasks"
    >
      <AppIcon name="panel" :size="20" />
    </button>
  </div>
</template>
