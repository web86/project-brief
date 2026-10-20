<script setup>
import { useRoute } from 'vue-router'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import ProjectNavigation from '../components/ProjectNavigation.vue'
const route = useRoute(),
  store = useProjectManagementStore()
</script>
<template>
  <div v-if="store.project" class="admin-project-shell" :key="route.params.uuid">
    <header v-if="!route.meta.taskDetail" class="admin-project-header">
      <div>
        <h1>{{ store.project.name }}</h1>
      </div>
      <ProjectNavigation />
    </header>
    <RouterView :key="route.params.uuid + ':' + route.meta.projectSection" />
  </div>
</template>
