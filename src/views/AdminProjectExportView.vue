<script setup>
import { inject, onBeforeUnmount, ref, watch } from 'vue'
import { exportRendererKey } from '../services/exportRenderer.js'
import { useRoute } from 'vue-router'
import { useProjectStore } from '../stores/project.js'
import { api } from '../api/client.js'
import { locale, t, formatApiError, LANGUAGE_CATALOG } from '../i18n/index.js'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import { saveProjectExport } from '../utils/projectExport.js'
import { toast } from '../services/toast.js'
import LoadingButton from '../components/LoadingButton.vue'

const browserPdf = inject(exportRendererKey, null)
const stage = ref('')
let operation
const cancel = () => {
  if (browserPdf && format.value === 'pdf') operation?.abort()
}
onBeforeUnmount(cancel)
const client = useRoute().meta.clientSection === 'export'
const store = client ? useProjectStore() : useProjectManagementStore()
const format = ref('pdf'),
  scope = ref('all'),
  language = ref(locale.value)
const defaultTitle = (language) => t('export.defaultDocumentTitle', {}, { locale: language })
const documentTitle = ref(defaultTitle(language.value))
const pageBreakBySection = ref(false)
watch(language, (value, previous) => {
  if (!documentTitle.value.trim() || documentTitle.value === defaultTitle(previous))
    documentTitle.value = defaultTitle(value)
})
const fieldDefaults = {
  description: true,
  expectedResult: true,
  priority: true,
  status: true,
  estimateHours: false,
  price: false,
  attachments: false,
  approvalDate: false,
  developerNotes: false,
}
const clientFields = [
  'description',
  'expectedResult',
  'priority',
  'status',
  'attachments',
  'approvalDate',
]
const fields = ref(
  Object.fromEntries(
    Object.entries(fieldDefaults).filter(([field]) => !client || clientFields.includes(field)),
  ),
)
const pending = ref(false),
  error = ref('')
async function download() {
  if (pending.value) return
  const projectId = store.project.id
  pending.value = true
  error.value = ''
  operation = new AbortController()
  try {
    const options = {
      format: format.value,
      scope: scope.value,
      locale: language.value,
      documentTitle: documentTitle.value.trim(),
      pageBreakBySection: pageBreakBySection.value,
      fields: { ...fields.value },
    }
    const result =
      browserPdf && format.value === 'pdf'
        ? await browserPdf({
            projectId,
            client,
            options,
            signal: operation.signal,
            onStage: (value) => {
              stage.value = value
            },
          })
        : await api.request(
            client
              ? `/api/client/project/${projectId}/export`
              : `/api/admin/projects/${projectId}/export`,
            {
              method: 'POST',
              responseType: 'blob',
              body: {
                format: format.value,
                scope: scope.value,
                locale: language.value,
                documentTitle: documentTitle.value.trim(),
                pageBreakBySection: pageBreakBySection.value,
                fields: { ...fields.value },
              },
            },
          )
    if (store.project?.id === projectId) saveProjectExport(result)
  } catch (cause) {
    if (store.project?.id !== projectId || operation.signal.aborted) return
    error.value =
      cause.errors?.scope?.[0] ||
      (browserPdf && cause.status === 413
        ? t('export.tooLarge')
        : browserPdf && !cause.status
          ? t('export.prototypeError')
          : formatApiError(cause))
    toast.error(error.value)
  } finally {
    pending.value = false
    stage.value = ''
    operation = null
  }
}
</script>
<template>
  <form class="surface admin-form export-form" :aria-busy="pending" @submit.prevent="download">
    <h2>{{ t('export.title') }}</h2>
    <p v-if="browserPdf" class="muted">{{ t('export.prototypeNotice') }}</p>
    <fieldset :disabled="pending">
      <legend>{{ t('export.format') }}</legend>
      <label><input v-model="format" type="radio" value="pdf" name="format" /> PDF</label>
      <label><input v-model="format" type="radio" value="docx" name="format" /> Word (.docx)</label>
    </fieldset>
    <fieldset :disabled="pending">
      <legend>{{ t('export.language') }}</legend>
      <label v-for="item in LANGUAGE_CATALOG" :key="item.code" :lang="item.code"
        ><input v-model="language" type="radio" :value="item.code" name="language" />
        {{ item.name }}</label
      >
    </fieldset>
    <div class="field">
      <label for="export-document-title">{{ t('export.documentTitle') }}</label>
      <input
        id="export-document-title"
        v-model="documentTitle"
        type="text"
        maxlength="160"
        :disabled="pending"
      />
    </div>
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
      <label
        ><input v-model="scope" type="radio" value="done" name="scope" />
        {{ t('export.done') }}</label
      >
    </fieldset>
    <fieldset :disabled="pending">
      <legend>{{ t('export.contents') }}</legend>
      <label v-for="(_, field) in fields" :key="field">
        <input v-model="fields[field]" type="checkbox" /> {{ t('export.' + field) }}
      </label>
    </fieldset>
    <fieldset :disabled="pending">
      <legend>{{ t('export.layout') }}</legend>
      <label
        ><input v-model="pageBreakBySection" type="checkbox" />
        {{ t('export.pageBreakBySection') }}</label
      >
    </fieldset>
    <p v-if="browserPdf && pending && stage" role="status" aria-live="polite">
      {{ t('export.stages.' + stage) }}
    </p>
    <button
      v-if="browserPdf && pending && format === 'pdf'"
      type="button"
      class="button"
      @click="cancel"
    >
      {{ t('ui.cancel') }}
    </button>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <LoadingButton type="submit" :busy="pending" class="button primary">{{
      t('export.download')
    }}</LoadingButton>
  </form>
</template>
