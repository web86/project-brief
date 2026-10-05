<script setup>
import { PRIORITIES } from '../constants/project'
defineProps({ priority: { type: String, required: true } })
</script>
<template>
  <span class="priority-label" :class="`tone-${PRIORITIES[priority]?.tone || 'gray'}`"
    ><span class="priority-bars" aria-hidden="true"><i /><i /><i /></span
    >{{ PRIORITIES[priority]?.label || priority }}</span
  >
</template>
