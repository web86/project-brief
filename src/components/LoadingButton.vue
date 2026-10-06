<script setup>
import { ref, watch } from 'vue'
import { t } from '../i18n/index.js'
const props = defineProps({ busy: Boolean, type: { type: String, default: 'button' } })
const element = ref(null)
const dimensions = ref({})
watch(
  () => props.busy,
  (busy) => {
    if (busy && element.value) {
      const box = element.value.getBoundingClientRect()
      dimensions.value = { width: `${box.width}px`, height: `${box.height}px` }
    } else dimensions.value = {}
  },
  { flush: 'sync' },
)
</script>
<template>
  <button
    ref="element"
    class="loading-button"
    :type="type"
    :disabled="busy || $attrs.disabled"
    :aria-busy="busy"
    :aria-label="busy ? t('flow.busy') : $attrs['aria-label']"
    :style="dimensions"
  >
    <span class="loading-button-label" :class="{ 'is-loading': busy }"><slot /></span
    ><span v-if="busy" class="loading-spinner button-spinner" aria-hidden="true" />
  </button>
</template>
