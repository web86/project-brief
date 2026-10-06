<script setup>
import { t } from '../i18n/index.js'

defineProps({ done: Number, total: Number, progress: Number })
</script>
<template>
  <div class="project-progress">
    <div class="progress-copy">
      <span>{{ t('flow.progress', { done, total }) }}</span>
      <strong>{{ progress }}%</strong>
    </div>
    <div
      class="progress-track"
      role="progressbar"
      :aria-valuenow="progress"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-label="t('ui.projectProgress')"
    >
      <span :style="{ width: `${progress}%` }" />
    </div>
  </div>
</template>
