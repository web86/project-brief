<script setup>
import { onMounted, onUnmounted, shallowRef } from 'vue'
import { t } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import AppIcon from './AppIcon.vue'
const resolve = (value) => (typeof value === 'function' ? value() : value)
const target = shallowRef(null)
let observer
onMounted(() => {
  const updateTarget = () => {
    target.value = Array.from(document.querySelectorAll('dialog[open]')).at(-1) || null
  }
  observer = new MutationObserver(updateTarget)
  observer.observe(document.body, {
    subtree: true,
    childList: true,
    attributes: true,
    attributeFilter: ['open'],
  })
  updateTarget()
})
onUnmounted(() => {
  observer?.disconnect()
  toast.clear()
})
</script>
<template>
  <Teleport :to="target" :disabled="!target">
    <div class="toast-viewport" :aria-label="t('pwa.notifications')">
      <div
        v-for="item in toast.items.value"
        :key="item.id"
        class="app-toast"
        :class="`toast-${item.type}`"
        :role="item.type === 'error' || item.type === 'warning' ? 'alert' : 'status'"
        aria-atomic="true"
        @mouseenter="toast.pause(item.id)"
        @mouseleave="toast.resume(item.id)"
        @focusin="toast.pause(item.id)"
        @focusout="!$event.currentTarget.contains($event.relatedTarget) && toast.resume(item.id)"
      >
        <AppIcon
          class="toast-status-icon"
          :name="item.type === 'success' ? 'check' : 'info'"
          :size="18"
        />
        <div class="toast-content">
          <button
            v-if="item.details && item.action"
            type="button"
            class="toast-event"
            :disabled="item.busy"
            @click="toast.act(item.id)"
          >
            <strong>{{ resolve(item.message) }}</strong
            ><span v-for="(line, index) in item.details" :key="index">{{ resolve(line) }}</span>
          </button>
          <div v-else-if="item.details" class="toast-event">
            <strong>{{ resolve(item.message) }}</strong>
            <span v-for="(line, index) in item.details" :key="index">{{ resolve(line) }}</span>
          </div>
          <p v-else>{{ resolve(item.message) }}</p>
          <button
            v-if="item.action && !item.details"
            type="button"
            class="text-button"
            :disabled="item.busy"
            @click="toast.act(item.id)"
          >
            {{ resolve(item.actionLabel) }}
          </button>
        </div>
        <button
          type="button"
          class="toast-close"
          :aria-label="t('pwa.dismiss')"
          @click="toast.dismiss(item.id)"
        >
          ×
        </button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-event {
  appearance: none;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: start;
  font: inherit;
  cursor: pointer;
  padding: 0;
  width: 100%;
  display: grid;
  gap: 4px;
  overflow-wrap: anywhere;
}
.toast-event span {
  display: block;
  font-size: 13px;
  line-height: 1.4;
}
</style>
