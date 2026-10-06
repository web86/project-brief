<script setup>
import { t } from '../i18n/index.js'

import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useProjectStore } from '../stores/project'
const store = useProjectStore()
const route = useRoute()
const task = computed(() => (route.params.id ? store.findTask(route.params.id) : null))
import TaskForm from '../components/TaskForm.vue'
import AppIcon from '../components/AppIcon.vue'
</script>
<template>
  <div class="form-page">
    <RouterLink :to="store.projectPath" class="back-link"
      ><AppIcon name="arrow" :size="17" />{{ t('ui.backToTheProject2') }}</RouterLink
    >
    <div class="page-heading">
      <h1>{{ t(task ? 'flow.editTitle' : 'ui.newIdea') }}</h1>
      <p>{{ t(task ? 'flow.editDescription' : 'ui.describeInYourOwnWordsWhatYouWould') }}</p>
    </div>
    <div class="form-layout">
      <div class="surface"><TaskForm :key="task?.id || 'new'" :task="task" /></div>
    </div>
  </div>
</template>
