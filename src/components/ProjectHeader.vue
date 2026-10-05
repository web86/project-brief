<script setup>
import { useProjectStore } from '../stores/project'
import ProjectProgress from './ProjectProgress.vue'
import AppIcon from './AppIcon.vue'
const store = useProjectStore()
</script>
<template>
  <header class="project-header">
    <div class="project-intro">
      <h1>{{ store.project.name }}</h1>
      <span class="website-label"
        ><AppIcon name="globe" :size="15" />{{ store.project.website }}</span
      >
    </div>
    <div class="project-overview">
      <ProjectProgress
        :done="store.counts.done"
        :total="store.counts.all"
        :progress="store.progress"
      /><RouterLink :to="store.newTaskPath" class="button primary"
        ><AppIcon name="plus" :size="19" />Добавить идею</RouterLink
      >
    </div>
  </header>
</template>
