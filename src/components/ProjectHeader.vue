<script setup>
import { useProjectStore } from '../stores/project'
import ProjectProgress from './ProjectProgress.vue'
import AppIcon from './AppIcon.vue'
const store = useProjectStore()
</script>
<template>
  <header class="project-header">
    <div class="project-intro">
      <div class="eyebrow"><span class="tiny-dot" /> ВАШ ПРОЕКТ</div>
      <h1>{{ store.project.name }}</h1>
      <p>{{ store.project.description }}</p>
      <span class="website-label"
        ><AppIcon name="globe" :size="15" />{{ store.project.website }}</span
      >
    </div>
    <div class="project-overview">
      <ProjectProgress
        :done="store.counts.done"
        :total="store.counts.all"
        :progress="store.progress"
      /><RouterLink to="/task/new" class="button primary"
        ><AppIcon name="plus" :size="19" />Хочу что-то изменить</RouterLink
      >
    </div>
  </header>
</template>
