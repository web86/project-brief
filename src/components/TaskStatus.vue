<script setup>
import { STATUSES } from '../constants/project'
defineProps({ status: { type: String, required: true } })
</script>
<template>
  <span class="status-badge" :class="`tone-${STATUSES[status]?.tone || 'gray'}`"
    ><span class="status-dot" />{{ STATUSES[status]?.label || status }}</span
  >
</template>
