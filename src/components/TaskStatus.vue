<script setup>
import { STATUSES } from '../constants/project'
defineProps({ status: { type: String, required: true } })
</script>
<template>
  <span
    class="status-badge"
    :class="[
      `tone-${STATUSES[status]?.tone || 'gray'}`,
      { 'is-in-progress': status === 'in_progress' },
    ]"
    ><span class="status-dot" /><span>{{ STATUSES[status]?.label || status }}</span
    ><span v-if="status === 'in_progress'" class="status-progress-dots" aria-hidden="true"
      ><span>.</span><span>.</span><span>.</span></span
    ></span
  >
</template>
