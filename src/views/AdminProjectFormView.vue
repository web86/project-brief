<script setup>
import LoadingButton from '../components/LoadingButton.vue'
import { t, formatApiError } from '../i18n/index.js'

import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api/client'
import ProjectFields from '../components/ProjectFields.vue'
const router = useRouter()
const data = ref({ title: '', website: '', currency: 'RUB' })
const errors = ref({})
const error = ref('')
const pending = ref(false)
async function create() {
  if (pending.value) return
  pending.value = true
  errors.value = {}
  error.value = ''
  try {
    const result = await api.request('/api/admin/projects', { method: 'POST', body: data.value })
    await router.push(`/admin/projects/${result.data.id}`)
  } catch (cause) {
    errors.value = cause.errors || {}
    error.value = cause
  } finally {
    pending.value = false
  }
}
</script>
<template>
  <div class="form-page">
    <RouterLink to="/admin" class="back-link">{{ t('ui.allProjects') }}</RouterLink>
    <div class="page-heading">
      <h1>{{ t('ui.newProject2') }}</h1>
      <p>{{ t('ui.giveTheProjectANameSoYourClient') }}</p>
    </div>
    <form class="surface admin-form" @submit.prevent="create">
      <ProjectFields v-model="data" :errors="errors" />
      <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
      <div class="form-actions">
        <RouterLink to="/admin" class="button secondary">{{ t('ui.cancel') }}</RouterLink
        ><LoadingButton type="submit" :busy="pending" class="button primary" :disabled="pending">
          {{ t('ui.createProject') }}
        </LoadingButton>
      </div>
    </form>
  </div>
</template>
