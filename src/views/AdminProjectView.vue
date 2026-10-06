<script setup>
import LoadingOverlay from '../components/LoadingOverlay.vue'
import LoadingButton from '../components/LoadingButton.vue'
import { t, formatApiError } from '../i18n/index.js'

import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import ProjectFields from '../components/ProjectFields.vue'
import ProjectSectionManager from '../components/ProjectSectionManager.vue'
import ProjectClientManager from '../components/ProjectClientManager.vue'
const route = useRoute()
const store = useProjectManagementStore()
const form = ref({})
const message = ref('')
const error = ref('')
const errors = ref({})
const pending = ref(false)
watch(
  () => route.params.uuid,
  async (id) => {
    error.value = ''
    message.value = ''
    try {
      await store.load(id)
      if (store.project?.id !== id) return
      form.value = {
        title: store.project.name,
        website: store.project.website,
        currency: store.project.currency,
        status: store.project.status,
      }
    } catch (cause) {
      error.value = cause
    }
  },
  { immediate: true },
)
async function save() {
  if (pending.value) return
  pending.value = true
  error.value = ''
  errors.value = {}
  try {
    await store.saveProject(form.value)
    message.value = { messageKey: 'ui.settingsSaved', params: {} }
  } catch (cause) {
    error.value = cause
    errors.value = cause.errors || {}
  } finally {
    pending.value = false
  }
}
</script>
<template>
  <div>
    <RouterLink to="/admin" class="back-link">{{ t('ui.allProjects') }}</RouterLink>
    <LoadingOverlay :active="store.loading" />
    <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
    <template v-if="store.project">
      <div class="admin-page-heading">
        <div>
          <h1>{{ store.project.name }}</h1>
          <p class="muted">
            {{
              t('flow.progress', { done: store.project.doneCount, total: store.project.tasksCount })
            }}
          </p>
        </div>
        <RouterLink :to="`/admin/projects/${store.project.id}/brief`" class="button primary">{{
          t('ui.openIdeas')
        }}</RouterLink>
      </div>
      <div class="admin-settings-layout" :key="route.params.uuid">
        <div class="management-column"><ProjectClientManager /><ProjectSectionManager /></div>
        <form class="surface admin-form" @submit.prevent="save">
          <h2>{{ t('ui.projectSettings') }}</h2>
          <ProjectFields v-model="form" :errors="errors" />
          <div class="field">
            <label for="project-status">{{ t('ui.projectAvailability') }}</label
            ><select id="project-status" v-model="form.status">
              <option value="active">{{ t('ui.active') }}</option>
              <option value="inactive">{{ t('ui.inactive') }}</option>
            </select>
          </div>
          <LoadingButton type="submit" :busy="pending" class="button primary" :disabled="pending">{{
            t('ui.saveSettings')
          }}</LoadingButton>
          <p v-if="message" class="admin-feedback" role="status">{{ formatApiError(message) }}</p>
        </form>
      </div>
    </template>
  </div>
</template>
