<script setup>
import { ref, watch } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import LoadingButton from './LoadingButton.vue'
const props = defineProps({ projectId: String })
const instructions = ref(''),
  pending = ref(false),
  error = ref(''),
  loaded = ref(false)
let revision = 0
watch(
  () => props.projectId,
  async (id) => {
    const current = ++revision
    loaded.value = false
    pending.value = false
    instructions.value = ''
    error.value = ''
    if (!id) return
    try {
      const data = await api.request(`/api/admin/projects/${id}/agent-instructions`)
      if (current === revision) {
        instructions.value = data.instructions
        loaded.value = true
      }
    } catch (cause) {
      if (current === revision) error.value = formatApiError(cause)
    }
  },
  { immediate: true },
)
async function save() {
  if (pending.value) return
  const current = revision
  pending.value = true
  error.value = ''
  try {
    await api.request(`/api/admin/projects/${props.projectId}/agent-instructions`, {
      method: 'PATCH',
      body: { instructions: instructions.value },
    })
  } catch (cause) {
    if (current === revision) error.value = formatApiError(cause)
  } finally {
    if (current === revision) pending.value = false
  }
}
</script>
<template>
  <form class="surface admin-form project-agent-instructions" @submit.prevent="save">
    <h2>{{ t('collaboration.projectInstruction') }}</h2>
    <p class="small muted">{{ t('collaboration.contextHint') }}</p>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <label for="project-agent-instructions">{{ t('collaboration.projectInstruction') }}</label
    ><textarea
      id="project-agent-instructions"
      v-model="instructions"
      maxlength="10000"
      rows="4"
      :disabled="pending || !loaded"
    /><LoadingButton
      type="submit"
      class="button secondary"
      :busy="pending"
      :disabled="pending || !loaded"
      >{{ t('ui.saveSettings') }}</LoadingButton
    >
  </form>
</template>
<style scoped>
.project-agent-instructions {
  margin-top: 16px;
  display: grid;
  gap: 12px;
}
</style>
