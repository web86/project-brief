<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import PushControls from './PushControls.vue'

defineProps({ persona: { type: String, required: true }, footer: { type: String, required: true } })
const mobile = ref(false)
let breakpoint
function resize() {
  mobile.value = !!breakpoint?.matches
}
onMounted(() => {
  breakpoint = globalThis.matchMedia?.('(max-width: 640px)')
  resize()
  breakpoint?.addEventListener('change', resize)
})
onUnmounted(() => breakpoint?.removeEventListener('change', resize))
</script>
<template>
  <Teleport :to="footer" :disabled="!mobile" defer>
    <PushControls :persona="persona" />
  </Teleport>
</template>
