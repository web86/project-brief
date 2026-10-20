<script setup>
import { computed } from 'vue'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { useRoute } from 'vue-router'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import { t } from '../i18n/index.js'
const route = useRoute(),
  store = useProjectManagementStore()
const unreadComments = computed(
  () =>
    useAdminNavigationStore().projects.find((project) => project.id === route.params.uuid)
      ?.unreadCommentCount || 0,
)
const sections = [
  'brief',
  'discussions',
  'clients',
  'structure',
  'notifications',
  'settings',
  'export',
]
</script>
<template>
  <div v-if="store.project" class="admin-project-shell" :key="route.params.uuid">
    <header class="admin-project-header">
      <div>
        <span class="small muted">{{ t('nav.project') }}</span>
        <h1>{{ store.project.name }}</h1>
      </div>
      <nav class="project-subnav" :aria-label="t('nav.projectNavigation')">
        <RouterLink
          v-for="section in sections"
          :key="section"
          :to="`/admin/projects/${route.params.uuid}/${section}`"
          :aria-current="route.meta.projectSection === section ? 'page' : undefined"
          :class="{ active: route.meta.projectSection === section }"
        >
          {{ t('nav.' + section) }}
          <span
            v-if="section === 'discussions' && unreadComments"
            class="discussion-badge"
            :aria-label="t('discussions.unreadLabel', { count: unreadComments })"
            >{{ unreadComments }}</span
          >
        </RouterLink>
      </nav>
    </header>
    <RouterView :key="route.params.uuid + ':' + route.meta.projectSection" />
  </div>
</template>
