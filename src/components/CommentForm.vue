<script setup>
import LoadingButton from './LoadingButton.vue'
import { t } from '../i18n/index.js'

import { nextTick, ref, watch } from 'vue'
import { useProjectStore } from '../stores/project'
import AppIcon from './AppIcon.vue'
const props = defineProps({ taskId: String })
const store = useProjectStore()
const text = ref('')
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
    text.value = ''
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
async function submit() {
  if (pending.value) return
  pending.value = true
  if (await store.addComment(props.taskId, text.value)) {
    text.value = ''
    sent.value = true
    clarifying.value = false
  }
  pending.value = false
}
</script>
<template>
  <form class="comment-form" @submit.prevent="submit">
    <label for="comment-text">{{
      t(store.isDeveloper ? 'flow.writeToClient' : 'flow.writeToDeveloper')
    }}</label
    ><textarea
      id="comment-text"
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
    <div class="comment-form-footer">
      <span class="muted small" aria-live="polite">{{ sent ? t('ui.messageAdded') : '' }}</span
      ><LoadingButton
        :busy="pending"
        type="submit"
        class="button primary"
        :disabled="pending || !text.trim() || store.storageBlocked"
      >
        <AppIcon name="send" :size="16" />{{ t('ui.send') }}
      </LoadingButton>
    </div>
  </form>
</template>
