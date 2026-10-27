<script setup>
import { ref } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import AppIcon from './AppIcon.vue'
import LoadingButton from './LoadingButton.vue'
const props = defineProps({ project: { type: Object, required: true } })
const busy = ref(false)
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
    :disabled="busy || project.feedback.requested || project.feedback.submitted"
    @click="request"
    ><AppIcon :name="project.feedback.submitted ? 'check' : 'star'" :size="17" />{{
      t(
        project.feedback.submitted
          ? 'feedback.submitted'
          : project.feedback.requested
            ? 'feedback.requested'
            : 'feedback.request',
      )
    }}</LoadingButton
  >
</template>
