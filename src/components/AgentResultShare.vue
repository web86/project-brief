<script setup>
import { ref, watch } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import BaseDialog from './BaseDialog.vue'
import LoadingButton from './LoadingButton.vue'
const props = defineProps({ comment: Object, taskId: String })
const emit = defineEmits(['close', 'shared'])
const text = ref(''),
  links = ref([]),
  files = ref([]),
  error = ref(''),
  busy = ref(false)
let submissionId = '',
  revision = 0
watch(
  () => [props.comment?.id, props.taskId],
  () => {
    revision++
    text.value = ''
    links.value = []
    files.value = []
    error.value = ''
    busy.value = false
    submissionId = props.comment ? crypto.randomUUID() : ''
  },
)
function close() {
  if (
    !busy.value &&
    ((!text.value && !links.value.length && !files.value.length) ||
      window.confirm(t('agents.discardPublication')))
  )
    emit('close')
}
async function publish() {
  if (busy.value) return
  const version = revision
  busy.value = true
  error.value = ''
  try {
    await api.request(`/api/admin/tasks/${props.taskId}/agent-results/${props.comment.id}/share`, {
      method: 'POST',
      body: { text: text.value, links: links.value, attachmentIds: files.value, submissionId },
    })
    if (version === revision) emit('shared')
  } catch (cause) {
    if (version === revision) error.value = formatApiError(cause)
  } finally {
    if (version === revision) busy.value = false
  }
}
</script>
<template>
  <BaseDialog
    :open="!!comment"
    :title="t('agents.shareClient')"
    id="agent-share-title"
    class="management-dialog agent-share-dialog"
    @close="close"
  >
    <form id="agent-result-publication" class="management-body" @submit.prevent="publish">
      <p class="small muted">{{ t('agents.shareHint') }}</p>
      <p v-if="error" class="field-error" role="alert">{{ error }}</p>
      <details>
        <summary>{{ t('agents.sourceReport') }}</summary>
        <p class="agent-source-preview" tabindex="0">{{ comment?.text }}</p>
      </details>
      <label class="field" for="agent-publication-text"
        >{{ t('agents.clientText')
        }}<textarea
          id="agent-publication-text"
          v-model="text"
          rows="4"
          maxlength="5000"
          required
          :disabled="busy"
        />
      </label>
      <fieldset
        v-if="comment?.agentReport?.pullRequestUrl || comment?.agentReport?.resultUrl"
        :disabled="busy"
      >
        <legend>{{ t('agents.selectLinks') }}</legend>
        <label
          v-for="key in ['pullRequestUrl', 'resultUrl'].filter(
            (key) => comment?.agentReport?.[key],
          )"
          :key="key"
          class="agent-share-choice"
          ><input type="checkbox" v-model="links" :value="key" /><span>{{
            comment.agentReport[key]
          }}</span></label
        >
      </fieldset>
      <fieldset v-if="comment?.attachments?.length" :disabled="busy">
        <legend>{{ t('agents.selectFiles') }}</legend>
        <label v-for="file in comment.attachments" :key="file.id" class="agent-share-choice"
          ><input type="checkbox" v-model="files" :value="file.id" /><span>{{
            file.name
          }}</span></label
        >
      </fieldset>
    </form>
    <template #footer
      ><button class="button secondary" :disabled="busy" @click="close">{{ t('ui.cancel') }}</button
      ><LoadingButton
        form="agent-result-publication"
        type="submit"
        class="button primary"
        :busy="busy"
        :disabled="busy || !text.trim()"
        >{{ t('agents.publishClient') }}</LoadingButton
      ></template
    >
  </BaseDialog>
</template>
<style scoped>
.agent-share-dialog,
.agent-share-dialog :deep(.modal-content) {
  max-height: 90dvh;
}
.agent-source-preview {
  max-height: 160px;
  overflow-y: auto;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
  font-size: 0.85rem;
}
.agent-share-choice {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 6px 0;
  font-size: 0.85rem;
}
.agent-share-choice input {
  flex: 0 0 auto;
  width: 18px;
  height: 18px;
  min-height: 18px;
  padding: 0;
  margin: 2px 0 0;
}
.agent-share-choice span {
  min-width: 0;
  overflow-wrap: anywhere;
}
fieldset {
  min-width: 0;
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 10px 12px;
}
</style>
