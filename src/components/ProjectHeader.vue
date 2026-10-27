<script setup>
import { t } from '../i18n/index.js'

import { useRoute } from 'vue-router'
import { useProjectStore } from '../stores/project'
import ProjectProgress from './ProjectProgress.vue'
import AppIcon from './AppIcon.vue'
import FeedbackRequestButton from './FeedbackRequestButton.vue'
import ProjectFeedback from './ProjectFeedback.vue'
const store = useProjectStore()
const route = useRoute()
</script>
<template>
  <header
    class="project-header"
    :class="{ 'admin-project-overview': route.meta.adminShell && route.meta.projectOverview }"
  >
    <div v-if="!route.meta.adminShell || route.meta.projectOverview" class="project-intro">
      <h1>{{ store.project.name }}</h1>
      <FeedbackRequestButton v-if="store.apiMode && store.isDeveloper" :project="store.project" />
      <ProjectFeedback v-else-if="store.apiMode" />
      <span v-if="!route.meta.adminShell && store.project.website" class="website-label"
        ><AppIcon name="globe" :size="15" />{{ store.project.website }}</span
      >
    </div>
    <div class="project-overview">
      <ProjectProgress
        :done="store.counts.done"
        :total="store.counts.all"
        :progress="store.progress"
      /><RouterLink v-if="store.sections.length" :to="store.newTaskPath" class="button primary"
        ><AppIcon name="plus" :size="19" />{{ t('ui.addIdea') }}</RouterLink
      >
    </div>
  </header>
</template>
