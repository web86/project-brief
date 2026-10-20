<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import { useProjectStore } from '../stores/project.js'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
const route = useRoute(),
  management = useProjectManagementStore(),
  store = useProjectStore()
const items = computed(() => {
  if (!route.meta.adminShell || route.meta.taskDetail) return []
  if (route.meta.personalSettings)
    return [
      { label: t('centralNotifications.mySettings'), to: '/admin/settings/notifications' },
      { label: t('nav.notifications') },
    ]
  const projects = { label: t('nav.projects'), to: '/admin' }
  if (route.path === '/admin/projects/new') return [projects, { label: t('nav.newProject') }]
  const project = management.project
  if (!route.params.uuid || project?.id !== route.params.uuid) return []
  const root = `/admin/projects/${route.params.uuid}/brief`
  const path = [projects, { label: project.name, to: root }]
  if (route.meta.projectSection !== 'brief') {
    path.push({ label: t('nav.' + route.meta.projectSection) })
  } else if (route.meta.editTask || route.path.endsWith('/task/new')) {
    path.push({ label: t('nav.brief'), to: root })
    const task = store.findTask(route.params.id)
    if (task) {
      path.push({ label: '#' + store.numberForTask(task), to: store.taskPath(task.id) })
      if (route.meta.editTask) path.push({ label: t('flow.editTitle') })
    } else path.push({ label: t('ui.newIdea') })
  }
  return path
})
</script>
<template>
  <nav v-if="items.length" class="breadcrumbs" :aria-label="t('nav.breadcrumbs')">
    <ol>
      <li v-for="(item, index) in items" :key="index">
        <AppIcon v-if="index" name="chevron" :size="12" />
        <span v-if="index === items.length - 1" aria-current="page" :title="item.label">{{
          item.label
        }}</span>
        <RouterLink v-else :to="item.to" :title="item.label">{{ item.label }}</RouterLink>
      </li>
    </ol>
  </nav>
</template>
