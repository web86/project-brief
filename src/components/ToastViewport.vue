<script setup>
import { onUnmounted } from 'vue'
import { t } from '../i18n/index.js'
import { toast } from '../services/toast.js'
const resolve = (value) => (typeof value === 'function' ? value() : value)
onUnmounted(toast.clear)
</script>
<template>
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
      <div class="toast-content">
        <p>{{ resolve(item.message) }}</p>
        <button
          v-if="item.action"
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
</template>
