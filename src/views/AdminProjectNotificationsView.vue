<script setup>
import ProjectNotificationSettings from '../components/ProjectNotificationSettings.vue'
import { useProjectManagementStore } from '../stores/projectManagement.js'
const store = useProjectManagementStore()
</script>
<template>
  <ProjectNotificationSettings v-if="store.project" :project-id="store.project.id" />
</template>
