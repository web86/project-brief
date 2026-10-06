<script setup>
import { onMounted, ref } from 'vue'
import { t } from '../i18n/index.js'
import { api } from '../api/client.js'
import { createPushDevice } from '../services/push.js'
import BaseDialog from './BaseDialog.vue'
import PushDevicePanel from './PushDevicePanel.vue'
const props = defineProps({ persona: { type: String, required: true } })
const open = ref(false)
const state = ref('loading')
const busy = ref(false)
const error = ref(false)
const device = createPushDevice({ request: api.request, prefix: `/api/${props.persona}/push` })
async function run(action) {
  if (busy.value) return
  busy.value = true
  error.value = false
  try {
    const result = await action()
    if (result) state.value = result.state
  } catch {
    error.value = true
  } finally {
    busy.value = false
  }
}
onMounted(() => run(device.load))
</script>
<template>
  <button type="button" class="text-button push-trigger" @click="open = true">
    {{ t('notifications.push') }}
  </button>
  <BaseDialog
    :open="open"
    :title="t('notifications.push')"
    id="push-settings-title"
    @close="open = false"
  >
    <PushDevicePanel
      :state="state"
      :busy="busy"
      :error="error"
      @enable="run(device.enable)"
      @disable="run(device.disable)"
      @retry="run(device.load)"
    />
  </BaseDialog>
</template>
