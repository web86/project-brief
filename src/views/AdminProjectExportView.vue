<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useProjectStore } from '../stores/project.js'
import { api } from '../api/client.js'
import { locale, t, formatApiError, LANGUAGE_CATALOG } from '../i18n/index.js'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import { saveProjectExport } from '../utils/projectExport.js'
import { toast } from '../services/toast.js'
import LoadingButton from '../components/LoadingButton.vue'

const client = useRoute().meta.clientSection === 'export'
const store = client ? useProjectStore() : useProjectManagementStore()
const format = ref('pdf'),
  scope = ref('all'),
  language = ref(locale.value)
const defaultTitle = (value) => t('export.defaultDocumentTitle', {}, { locale: value })
const documentTitle = ref(defaultTitle(language.value))
const pageBreakBySection = ref(false)
const stage = ref('')
const pending = ref(false),
  error = ref('')
let operation
const cancel = () => operation?.abort()
onBeforeUnmount(cancel)
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
async function download(selectedFormat = 'pdf') {
  if (pending.value) return
  const projectId = store.project.id
  format.value = selectedFormat
  pending.value = true
  error.value = ''
  const controller = new AbortController()
  operation = controller
  try {
    const options = {
      format: selectedFormat,
      scope: scope.value,
      locale: language.value,
      documentTitle: documentTitle.value.trim(),
      pageBreakBySection: pageBreakBySection.value,
      fields: { ...fields.value },
    }
    let result
    if (selectedFormat === 'pdf') {
      stage.value = 'preparing'
      const { createBrowserPdfAdapter } = await import('../services/pdf/index.js')
      controller.signal.throwIfAborted()
      result = await createBrowserPdfAdapter()({
        projectId,
        client,
        options,
        signal: controller.signal,
        onStage: (value) => {
          stage.value = value
        },
      })
    } else {
      result = await api.request(
        client
          ? `/api/client/project/${projectId}/export`
          : `/api/admin/projects/${projectId}/export`,
        {
          method: 'POST',
          responseType: 'blob',
          body: options,
          signal: controller.signal,
        },
      )
    }
    controller.signal.throwIfAborted()
    if (store.project?.id === projectId) saveProjectExport(result)
  } catch (cause) {
    if (store.project?.id !== projectId || controller.signal.aborted) return
    error.value =
      cause.errors?.scope?.[0] ||
      (cause.status === 413
        ? t('export.tooLarge')
        : selectedFormat === 'pdf' && !cause.status
          ? t('export.pdfError')
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
  <form
    class="surface admin-form export-form"
    :aria-busy="pending"
    @submit.prevent="download('pdf')"
  >
    <h2>{{ t('export.title') }}</h2>
    <div class="export-grid">
      <section class="export-card" :aria-label="t('export.document')">
        <h3>{{ t('export.document') }}</h3>
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
          <div class="export-options">
            <label v-for="value in ['all', 'approved', 'done']" :key="value" class="export-option">
              <input v-model="scope" type="radio" :value="value" name="scope" />{{
                t('export.' + value)
              }}
            </label>
          </div>
        </fieldset>
        <fieldset :disabled="pending">
          <legend>{{ t('export.layout') }}</legend>
          <label class="export-option"
            ><input v-model="pageBreakBySection" type="checkbox" />{{
              t('export.pageBreakBySection')
            }}</label
          >
        </fieldset>
      </section>
      <section class="export-card" :aria-label="t('export.contents')">
        <fieldset :disabled="pending">
          <legend>{{ t('export.contents') }}</legend>
          <div class="export-fields">
            <label v-for="(_, field) in fields" :key="field" class="export-option">
              <input v-model="fields[field]" type="checkbox" :name="field" />{{
                t('export.' + field)
              }}
            </label>
          </div>
        </fieldset>
        <div class="field">
          <label for="export-language">{{ t('export.language') }}</label>
          <select id="export-language" v-model="language" :disabled="pending">
            <option
              v-for="item in LANGUAGE_CATALOG"
              :key="item.code"
              :value="item.code"
              :lang="item.code"
            >
              {{ item.name }}
            </option>
          </select>
        </div>
      </section>
    </div>
    <div class="export-actions">
      <div class="export-action-buttons">
        <LoadingButton
          type="submit"
          :busy="pending && format === 'pdf'"
          :disabled="pending"
          class="button primary"
          >{{ t('export.downloadPdf') }}</LoadingButton
        >
        <LoadingButton
          type="button"
          :busy="pending && format === 'docx'"
          :disabled="pending"
          class="button secondary"
          @click="download('docx')"
          >{{ t('export.downloadDocx') }}</LoadingButton
        >
        <button v-if="pending" type="button" class="button text" @click="cancel">
          {{ t('ui.cancel') }}
        </button>
      </div>
      <p v-if="pending && stage" role="status" aria-live="polite">
        {{ t('export.stages.' + stage) }}
      </p>
      <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    </div>
  </form>
</template>
