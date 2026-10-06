<script setup>
import LoadingOverlay from '../components/LoadingOverlay.vue'
import { t, formatApiError } from '../i18n/index.js'

import { onMounted, ref } from 'vue'
import { api } from '../api/client'
const projects = ref([])
const loading = ref(true)
const error = ref('')
async function load() {
  loading.value = true
  error.value = ''
  try {
    projects.value = (await api.request('/api/admin/projects')).data
  } catch (cause) {
    error.value = cause
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>
<template>
  <div>
    <div class="admin-page-heading">
      <div>
        <h1>{{ t('ui.projects') }}</h1>
        <p class="muted">{{ t('ui.ideasThatBecomeFinishedWebsites') }}</p>
      </div>
      <RouterLink to="/admin/projects/new" class="button primary">{{
        t('ui.newProject')
      }}</RouterLink>
    </div>
    <LoadingOverlay :active="loading" />
    <div v-if="error" role="alert">
      <p class="field-error">{{ formatApiError(error) }}</p>
      <button class="button secondary" @click="load">{{ t('ui.tryAgain') }}</button>
    </div>
    <div v-else-if="!projects.length" class="empty-state">
      <h2>{{ t('ui.startWithYourFirstProject') }}</h2>
      <p>{{ t('ui.createAProjectAndSendYourClientA') }}</p>
    </div>
    <div v-else class="admin-project-list">
      <article v-for="project in projects" :key="project.id" class="surface admin-project-card">
        <div>
          <h2>{{ project.name }}</h2>
          <p class="muted">{{ project.website || t('ui.noWebsiteAddress') }}</p>
          <p class="small muted">
            {{ t('flow.progress', { done: project.doneCount, total: project.tasksCount }) }} ·
            {{ project.status === 'active' ? t('ui.active') : t('ui.inactive') }}
          </p>
        </div>
        <div class="admin-project-actions">
          <RouterLink :to="`/admin/projects/${project.id}/brief`" class="button primary">{{
            t('ui.open')
          }}</RouterLink
          ><RouterLink
            :to="`/admin/projects/${project.id}#client-access`"
            class="button secondary"
            >{{ t('ui.clients') }}</RouterLink
          ><RouterLink :to="`/admin/projects/${project.id}`" class="text-button">{{
            t('ui.settings')
          }}</RouterLink>
        </div>
      </article>
    </div>
  </div>
</template>
