<script setup>
import { useRoute } from 'vue-router'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import { t } from '../i18n/index.js'
const route = useRoute(),
  store = useProjectManagementStore()
const sections = ['brief', 'clients', 'structure', 'notifications', 'settings', 'export']
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
        </RouterLink>
      </nav>
    </header>
    <RouterView :key="route.params.uuid + ':' + route.meta.projectSection" />
  </div>
</template>
