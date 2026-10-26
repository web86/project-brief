<script setup>
import mark from '../assets/brand-mark.svg?raw'

defineProps({
  compact: Boolean,
  size: { type: [Number, String], default: 30 },
  theme: {
    type: String,
    default: 'auto',
    validator: (value) => ['auto', 'light', 'dark'].includes(value),
  },
})
</script>
<template>
  <span
    class="brand-logo"
    :class="{ 'brand-logo-compact': compact }"
    :data-brand-theme="theme"
    :style="{ '--brand-size': typeof size === 'number' ? `${size}px` : size }"
    role="img"
    aria-label="projectbrief"
  >
    <span class="brand-mark" aria-hidden="true" v-html="mark" />
    <span v-if="!compact" class="brand-wordmark" aria-hidden="true"
      >project<span class="brand-light">brief</span></span
    >
  </span>
</template>
