<script setup>
import LoadingButton from './LoadingButton.vue'
import { t, formatApiError } from '../i18n/index.js'

import { nextTick, ref, watch, onBeforeUnmount } from 'vue'
import { useProjectStore } from '../stores/project'
import AppIcon from './AppIcon.vue'
import FileUploader from './FileUploader.vue'
import { toast } from '../services/toast.js'
const props = defineProps({
  taskId: String,
  label: String,
  general: Boolean,
  sendComment: Function,
})
const store = useProjectStore()
const text = defineModel({ type: String, default: '' })
const files = ref([])
const uploader = ref(null)
const processing = ref(false)
const dragging = ref(false)
let retrySignature = '',
  submissionId = '',
  revision = 0,
  active = true
onBeforeUnmount(() => {
  active = false
})
const sent = ref(false)
const pending = ref(false)
const textarea = ref(null)
const clarifying = ref(false)
async function focusForClarification() {
  if (!store.isDeveloper) return
  clarifying.value = true
  await nextTick()
  const field = textarea.value
  if (!field) return
  field.focus({ preventScroll: true })
  const rect = field.getBoundingClientRect()
  if (rect.top < 0 || rect.bottom > window.innerHeight) {
    field.scrollIntoView({
      behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
      block: 'center',
    })
  }
}
defineExpose({ focusForClarification })
watch(
  () => store.currentMode,
  () => {
    clarifying.value = false
  },
)
watch(
  () => props.taskId,
  () => {
    revision++
    text.value = ''
    files.value = []
    sent.value = false
    clarifying.value = false
  },
)
watch(
  text,
  () => {
    sent.value = false
  },
  { flush: 'sync' },
)
function drop(event) {
  dragging.value = false
  uploader.value?.drop(event)
}
async function submit() {
  if (pending.value || processing.value || (!text.value.trim() && !files.value.length)) return
  const signature = JSON.stringify([text.value.trim(), files.value.map((file) => file.id)])
  if (signature !== retrySignature) {
    submissionId = crypto.randomUUID()
    retrySignature = signature
  }
  const current = revision
  pending.value = true
  try {
    const success = props.sendComment
      ? await props.sendComment(text.value, files.value, submissionId)
      : await store.addComment(props.taskId, text.value, files.value, submissionId)
    if (success && active && current === revision) {
      text.value = ''
      files.value = []
      retrySignature = ''
      sent.value = true
      clarifying.value = false
    }
  } catch (error) {
    if (active && current === revision) toast.error(() => formatApiError(error))
  } finally {
    pending.value = false
  }
}
</script>
<template>
  <form
    class="comment-form"
    :class="{ dragging }"
    :aria-busy="pending"
    @submit.prevent="submit"
    @paste="uploader?.paste($event)"
    @dragover.prevent="dragging = true"
    @dragleave.self="dragging = false"
    @drop.prevent="drop"
  >
    <label :for="general ? 'project-comment' : 'comment-text'">{{
      label ||
      (general
        ? t('discussions.message')
        : t(store.isDeveloper ? 'flow.writeToClient' : 'flow.writeToDeveloper'))
    }}</label
    ><textarea
      :id="general ? 'project-comment' : 'comment-text'"
      :disabled="pending"
      ref="textarea"
      v-model="text"
      rows="2"
      maxlength="5000"
      :placeholder="
        clarifying && store.isDeveloper
          ? t('ui.whatNeedsToBeClarifiedWithTheClient')
          : t('ui.askAQuestionOrAddDetails')
      "
    />
    <FileUploader
      ref="uploader"
      v-model="files"
      compact
      :max-files="5"
      :max-total-size="40 * 1024 * 1024"
      :disabled="pending"
      @busy="processing = $event"
    />
    <div class="comment-form-footer">
      <span class="muted small" aria-live="polite">{{ sent ? t('ui.messageAdded') : '' }}</span
      ><LoadingButton
        :busy="pending"
        type="submit"
        class="button primary"
        :disabled="
          pending ||
          processing ||
          (!text.trim() && !files.length) ||
          (!general && store.storageBlocked)
        "
      >
        <AppIcon name="send" :size="16" />{{ t('ui.send') }}
      </LoadingButton>
    </div>
  </form>
</template>
