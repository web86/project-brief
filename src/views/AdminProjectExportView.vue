<script setup>
import { ref } from 'vue'
import { api } from '../api/client.js'
import { locale, t, formatApiError } from '../i18n/index.js'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import { saveProjectExport } from '../utils/projectExport.js'
import { toast } from '../services/toast.js'
import LoadingButton from '../components/LoadingButton.vue'

const store = useProjectManagementStore()
const format = ref('pdf'),
  scope = ref('all'),
  language = ref(locale.value)
const fields = ref({
  description: true,
  expectedResult: true,
  priority: true,
  status: true,
  estimateHours: false,
  price: false,
})
const pending = ref(false),
  error = ref('')
async function download() {
  if (pending.value) return
  const projectId = store.project.id
  pending.value = true
  error.value = ''
  try {
    const result = await api.request(`/api/admin/projects/${projectId}/export`, {
      method: 'POST',
      responseType: 'blob',
      body: {
        format: format.value,
        scope: scope.value,
        locale: language.value,
        fields: { ...fields.value },
      },
    })
    if (store.project?.id === projectId) saveProjectExport(result)
  } catch (cause) {
    if (store.project?.id !== projectId) return
    error.value = cause.errors?.scope?.[0] || formatApiError(cause)
    toast.error(error.value)
  } finally {
    pending.value = false
  }
}
</script>
<template>
  <form class="surface admin-form export-form" :aria-busy="pending" @submit.prevent="download">
    <h2>{{ t('export.title') }}</h2>
    <fieldset :disabled="pending">
      <legend>{{ t('export.format') }}</legend>
      <label><input v-model="format" type="radio" value="pdf" name="format" /> PDF</label>
      <label><input v-model="format" type="radio" value="docx" name="format" /> Word (.docx)</label>
    </fieldset>
    <fieldset :disabled="pending">
      <legend>{{ t('export.tasks') }}</legend>
      <label
        ><input v-model="scope" type="radio" value="all" name="scope" />
        {{ t('export.all') }}</label
      >
      <label
        ><input v-model="scope" type="radio" value="approved" name="scope" />
        {{ t('export.approved') }}</label
      >
    </fieldset>
    <fieldset :disabled="pending">
      <legend>{{ t('export.language') }}</legend>
      <label><input v-model="language" type="radio" value="ru" name="language" /> Русский</label>
      <label><input v-model="language" type="radio" value="en" name="language" /> English</label>
    </fieldset>
    <fieldset :disabled="pending">
      <legend>{{ t('export.contents') }}</legend>
      <label v-for="(_, field) in fields" :key="field">
        <input v-model="fields[field]" type="checkbox" /> {{ t('export.' + field) }}
      </label>
    </fieldset>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <LoadingButton type="submit" :busy="pending" class="button primary">{{
      t('export.download')
    }}</LoadingButton>
  </form>
</template>
