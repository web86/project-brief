<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
const area = ref(null),
  content = ref(null),
  top = ref(false),
  bottom = ref(false)
let resize, mutation, frame
function sync() {
  cancelAnimationFrame(frame)
  frame = requestAnimationFrame(() => {
    const node = area.value
    if (!node) return
    top.value = node.scrollTop > 2
    bottom.value = node.scrollTop + node.clientHeight < node.scrollHeight - 2
  })
}
onMounted(async () => {
  await nextTick()
  resize = new ResizeObserver(sync)
  resize.observe(area.value)
  resize.observe(content.value)
  mutation = new MutationObserver(sync)
  mutation.observe(content.value, { childList: true, subtree: true })
  sync()
})
onBeforeUnmount(() => {
  resize?.disconnect()
  mutation?.disconnect()
  cancelAnimationFrame(frame)
})
</script>
<template>
  <div class="sidebar-scroll-region">
    <div
      ref="area"
      class="sidebar-scroll-area"
      :data-fade-top="top"
      :data-fade-bottom="bottom"
      @scroll.passive="sync"
    >
      <div ref="content"><slot /></div>
    </div>
  </div>
</template>
