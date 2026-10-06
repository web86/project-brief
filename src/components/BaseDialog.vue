<script setup>
import { t } from '../i18n/index.js'

import { onMounted, ref, watch } from 'vue'
import AppIcon from './AppIcon.vue'
const props = defineProps({
  open: Boolean,
  title: String,
  id: { type: String, default: 'dialog-title' },
})
const emit = defineEmits(['close'])
const dialog = ref(null)
function syncDialog() {
  if (!dialog.value) return
  if (props.open && !dialog.value.open) dialog.value.showModal()
  else if (!props.open && dialog.value.open) dialog.value.close()
}
onMounted(syncDialog)
watch(() => props.open, syncDialog)
</script>
<template>
  <dialog
    ref="dialog"
    class="modal"
    :aria-labelledby="id"
    @cancel.prevent="emit('close')"
    @click="(event) => event.target === dialog && emit('close')"
  >
    <div class="modal-content">
      <div class="modal-header">
        <h2 :id="id">{{ title }}</h2>
        <button
          type="button"
          class="icon-button"
          :aria-label="t('ui.closeDialog')"
          @click="emit('close')"
        >
          <AppIcon name="close" />
        </button>
      </div>
      <slot />
      <div v-if="$slots.footer" class="modal-footer"><slot name="footer" /></div>
    </div>
  </dialog>
</template>
