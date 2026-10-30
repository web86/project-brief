<script setup>
import { ref, watch } from 'vue'
import { useProjectStore } from '../stores/project.js'
import { t, formatApiError } from '../i18n/index.js'
import BaseDialog from './BaseDialog.vue'
import LoadingButton from './LoadingButton.vue'
import AppIcon from './AppIcon.vue'
const props = defineProps({ task: Object })
const store = useProjectStore()
const pending = ref(false),
  confirmation = ref(null),
  error = ref(null)
function cancel() {
  if (!pending.value) {
    confirmation.value = null
    error.value = null
  }
}
function requestCompletion() {
  if (pending.value || props.task?.status !== 'review') return
  error.value = null
  confirmation.value = { id: props.task.id, version: props.task.editVersion }
}
async function change(status) {
  if (pending.value || props.task?.status !== 'review') return
  const attempt = confirmation.value || { id: props.task.id, version: props.task.editVersion }
  pending.value = true
  error.value = null
  try {
    if (await store.changeClientStatus(attempt.id, status, attempt.version))
      confirmation.value = null
    else error.value = store.apiError || { status: 409 }
  } finally {
    pending.value = false
  }
}
watch(
  () => props.task?.id,
  () => {
    confirmation.value = null
    error.value = null
  },
)
</script>
<template>
  <section
    v-if="!store.isDeveloper && (task?.status === 'review' || confirmation || error)"
    class="client-status-actions"
    :aria-label="t('ui.ideaStatus')"
  >
    <div v-if="task?.status === 'review'" class="client-status-buttons">
      <LoadingButton
        type="button"
        class="button secondary"
        :busy="pending && !confirmation"
        :disabled="pending || store.storageBlocked"
        @click="change('approved')"
        ><AppIcon name="arrow" :size="17" />{{ t('clientStatus.return') }}</LoadingButton
      >
      <button
        type="button"
        class="button primary"
        :disabled="pending || store.storageBlocked"
        @click="requestCompletion"
      >
        <AppIcon name="check" :size="17" />{{ t('clientStatus.complete') }}
      </button>
    </div>
    <p v-if="error && !confirmation" class="field-error" role="alert">
      {{ error.status === 409 ? t('clientStatus.conflict') : formatApiError(error) }}
    </p>
    <BaseDialog
      :open="!!confirmation"
      :title="t('clientStatus.title')"
      id="client-complete-title"
      class="client-complete-dialog"
      @close="cancel"
    >
      <p>{{ t('clientStatus.body') }}</p>
      <p v-if="error" class="field-error" role="alert">
        {{ error.status === 409 ? t('clientStatus.conflict') : formatApiError(error) }}
      </p>
      <template #footer
        ><button type="button" class="button secondary" :disabled="pending" @click="cancel">
          {{ t('ui.cancel') }}</button
        ><LoadingButton
          type="button"
          class="button primary"
          :busy="pending"
          :disabled="pending || task?.status !== 'review'"
          @click="change('done')"
          >{{ t('clientStatus.complete') }}</LoadingButton
        ></template
      >
    </BaseDialog>
  </section>
</template>
<style scoped>
.client-status-actions {
  margin: 20px 0;
  display: grid;
  gap: 12px;
}
.client-status-buttons {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.client-complete-dialog {
  width: min(480px, calc(100% - 32px));
}
.client-complete-dialog p {
  line-height: 1.6;
}
.client-complete-dialog p + p {
  margin-top: 12px;
}
@media (max-width: 600px) {
  .client-status-buttons .button {
    flex: 1 1 auto;
  }
}
</style>
