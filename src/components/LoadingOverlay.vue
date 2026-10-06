<script setup>
import { t } from '../i18n/index.js'
defineProps({ active: Boolean, block: Boolean })
</script>
<template>
  <div
    v-if="active"
    class="loading-overlay"
    :class="{ 'block-loading': block }"
    role="status"
    aria-live="polite"
  >
    <span class="loading-spinner" aria-hidden="true" /><span class="sr-only">{{
      t('flow.busy')
    }}</span>
  </div>
</template>
