<script setup>
import { toast } from '../services/toast.js'
import LoadingButton from '../components/LoadingButton.vue'
import { t, formatApiError } from '../i18n/index.js'

import { ref, watch } from 'vue'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import ProjectFields from '../components/ProjectFields.vue'
const nav = useAdminNavigationStore()
const store = useProjectManagementStore()
const form = ref({})
const error = ref('')
const errors = ref({})
const pending = ref(false)
watch(
  () => store.project,
  (project) => {
    if (project)
      form.value = {
        title: project.name,
        website: project.website,
        currency: project.currency,
        status: project.status,
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
    const project = await store.saveProject(form.value)
    nav.updateProject(project)
    toast.success(() => t('ui.settingsSaved'))
  } catch (cause) {
    errors.value = cause.errors || {}
    if (cause.status !== 422) toast.error(() => formatApiError(cause))
  } finally {
    pending.value = false
  }
}
</script>
<template>
  <form class="surface admin-form" @submit.prevent="save">
    <h2>{{ t('ui.projectSettings') }}</h2>
    <ProjectFields v-model="form" :errors="errors" />
    <div class="field">
      <label for="project-status">{{ t('ui.projectAvailability') }}</label>
      <select id="project-status" v-model="form.status">
        <option value="active">{{ t('ui.active') }}</option>
        <option value="inactive">{{ t('ui.inactive') }}</option>
      </select>
      <p v-if="errors.status" class="field-error">{{ t('flow.fieldError') }}</p>
    </div>
    <LoadingButton type="submit" :busy="pending" class="button primary" :disabled="pending">{{
      t('ui.saveSettings')
    }}</LoadingButton>
  </form>
</template>
