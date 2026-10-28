<script setup>
import { ref, watch } from 'vue'
import BaseDialog from './BaseDialog.vue'
import { formatDate } from '../i18n/index.js'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import AppIcon from './AppIcon.vue'
import LoadingButton from './LoadingButton.vue'
const props = defineProps({ project: { type: Object, required: true } })
const busy = ref(false),
  open = ref(false),
  reviews = ref([]),
  error = ref(null)
let revision = 0
watch(
  () => props.project.id,
  () => {
    revision++
    open.value = false
    reviews.value = []
  },
)
async function view() {
  if (busy.value) return
  open.value = true
  error.value = null
  busy.value = true
  const current = revision
  try {
    const result = await api.request(`/api/admin/projects/${props.project.id}/feedback`)
    if (current === revision) reviews.value = result.data
  } catch (cause) {
    if (current === revision) error.value = cause
  } finally {
    if (current === revision) busy.value = false
  }
}
async function request() {
  if (busy.value) return
  busy.value = true
  try {
    await api.request(`/api/admin/projects/${props.project.id}/feedback/request`, {
      method: 'POST',
    })
    props.project.feedback = { ...props.project.feedback, requested: true }
    toast.success(() => t('feedback.requestSaved'))
  } catch (error) {
    toast.error(() => formatApiError(error))
  } finally {
    busy.value = false
  }
}
</script>
<template>
  <LoadingButton
    v-if="project.feedback?.eligible || project.feedback?.submitted"
    class="button feedback-request"
    :busy="busy"
    :disabled="busy || (project.feedback.requested && !project.feedback.submitted)"
    @click="project.feedback.submitted ? view() : request()"
    ><AppIcon :name="project.feedback.submitted ? 'check' : 'star'" :size="17" />{{
      t(
        project.feedback.submitted
          ? 'liveSync.viewFeedback'
          : project.feedback.requested
            ? 'feedback.requested'
            : 'feedback.request',
      )
    }}</LoadingButton
  >
  <BaseDialog
    v-if="open"
    :open="open"
    :title="t('liveSync.viewFeedback')"
    :id="'feedback-review-' + project.id"
    @close="open = false"
  >
    <p v-if="busy" role="status">{{ t('nav.loading') }}</p>
    <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
    <article v-for="(review, index) in reviews" :key="index" class="feedback-review">
      <p>
        <strong>{{ t('feedback.stars', { count: review.rating }) }}</strong> · {{ review.author }} ·
        {{ formatDate(review.submittedAt) }}
      </p>
      <p class="preserve-text">{{ review.text }}</p>
    </article>
    <template #footer
      ><button type="button" class="button secondary" @click="open = false">
        {{ t('ui.closeDialog') }}
      </button></template
    >
  </BaseDialog>
</template>
