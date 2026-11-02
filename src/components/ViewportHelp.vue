<script setup>
import { ref, useId, onMounted, onBeforeUnmount, nextTick } from 'vue'
import AppIcon from './AppIcon.vue'
defineProps({ text: { type: String, required: true } })
let hideTimer
const anchor = ref(null),
  popup = ref(null),
  visible = ref(false),
  id = useId()
function position() {
  if (!visible.value || !anchor.value || !popup.value) return
  const viewport = window.visualViewport
  const left = viewport?.offsetLeft || 0,
    top = viewport?.offsetTop || 0
  const width = viewport?.width || window.innerWidth,
    height = viewport?.height || window.innerHeight
  popup.value.style.width = `${Math.max(1, Math.min(260, width - 16))}px`
  popup.value.style.maxHeight = `${Math.max(1, height - 16)}px`
  const box = anchor.value.getBoundingClientRect(),
    tip = popup.value.getBoundingClientRect()
  popup.value.style.left = `${Math.max(left + 8, Math.min(box.left, left + width - tip.width - 8))}px`
  popup.value.style.top = `${Math.max(top + 8, Math.min(box.bottom + 8, top + height - tip.height - 8))}px`
}
async function show() {
  clearTimeout(hideTimer)
  popup.value?.showPopover()
  visible.value = true
  await nextTick()
  position()
}
function hide() {
  popup.value?.hidePopover()
  visible.value = false
}
function leave(event) {
  if (event.pointerType === 'mouse' && document.activeElement !== anchor.value)
    hideTimer = setTimeout(hide, 150)
}
function escape(event) {
  if (visible.value) {
    event.preventDefault()
    event.stopPropagation()
    hide()
  }
}
function outside(event) {
  if (!anchor.value?.contains(event.target) && !popup.value?.contains(event.target)) hide()
}
onMounted(() => {
  window.addEventListener('resize', position)
  window.addEventListener('scroll', position, true)
  window.visualViewport?.addEventListener('resize', position)
  window.visualViewport?.addEventListener('scroll', position)
  document.addEventListener('pointerdown', outside)
})
onBeforeUnmount(() => {
  clearTimeout(hideTimer)
  window.removeEventListener('resize', position)
  window.removeEventListener('scroll', position, true)
  window.visualViewport?.removeEventListener('resize', position)
  window.visualViewport?.removeEventListener('scroll', position)
  document.removeEventListener('pointerdown', outside)
})
</script>
<template>
  <button
    ref="anchor"
    type="button"
    class="workspace-help"
    :aria-label="text"
    :aria-describedby="visible ? id : undefined"
    :aria-expanded="visible"
    @pointerenter="$event.pointerType === 'mouse' && show()"
    @pointerleave="leave"
    @focus="show"
    @blur="hide"
    @click="show"
    @keydown.esc="escape"
  >
    <AppIcon name="info" :size="14" />
  </button>
  <Teleport to="body"
    ><div
      :id="id"
      ref="popup"
      popover="auto"
      role="tooltip"
      class="viewport-workspace-tooltip"
      @pointerenter="show"
      @pointerleave="leave"
      @toggle="visible = $event.newState === 'open'"
    >
      {{ text }}
    </div></Teleport
  >
</template>
<style>
button.workspace-help {
  border: 0;
  background: transparent;
  color: inherit;
  padding: 4px;
  min-height: 24px;
}
.viewport-workspace-tooltip {
  position: fixed;
  inset: auto;
  margin: 0;
  width: min(260px, calc(100vw - 16px));
  max-width: calc(100vw - 16px);
  max-height: calc(100dvh - 16px);
  overflow: auto;
  padding: 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface);
  color: var(--text);
  font-size: 12px;
  line-height: 1.5;
  box-shadow: 0 4px 14px var(--popover-shadow);
  overflow-wrap: anywhere;
}
</style>
