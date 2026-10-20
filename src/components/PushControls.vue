<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { t } from '../i18n/index.js'
import { api } from '../api/client.js'
import { createPushDevice } from '../services/push.js'
import { createPushEnrollment } from '../services/pushEnrollment.js'
import { toast } from '../services/toast.js'
import BaseDialog from './BaseDialog.vue'
import PushDevicePanel from './PushDevicePanel.vue'
import AppIcon from './AppIcon.vue'
const props = defineProps({ persona: { type: String, required: true }, inline: Boolean })
const open = ref(false)
const state = ref('loading')
const busy = ref(false)
const error = ref(false)
const device = createPushDevice({ request: api.request, prefix: `/api/${props.persona}/push` })
const enrollment = createPushEnrollment({
  device,
  toast,
  update(result) {
    if (result.state) state.value = result.state
    if ('busy' in result) busy.value = result.busy
    if ('error' in result) error.value = result.error
  },
})
onMounted(enrollment.start)
onUnmounted(enrollment.dispose)
</script>
<template>
  <PushDevicePanel
    v-if="inline"
    :state="state"
    :busy="busy"
    :error="error"
    @enable="enrollment.enable"
    @disable="enrollment.disable"
    @retry="enrollment.retry"
  />
  <button v-else type="button" class="text-button push-trigger" @click="open = true">
    <AppIcon name="bell" :size="18" class="push-mobile-icon" />
    <span>{{ t('notifications.push') }}</span>
    <span class="push-mobile-action">{{ t('notifications.configure') }}</span>
  </button>
  <Teleport v-if="!inline" to="body" defer>
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
        @enable="enrollment.enable"
        @disable="enrollment.disable"
        @retry="enrollment.retry"
      />
    </BaseDialog>
  </Teleport>
</template>
