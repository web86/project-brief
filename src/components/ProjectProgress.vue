<script setup>
defineProps({ done: Number, total: Number, progress: Number })
</script>
<template>
  <div class="project-progress">
    <div class="progress-copy"><span><strong>{{ done }}</strong> из {{ total }} задач выполнено</span><strong>{{ progress }}%</strong></div>
    <div class="progress-track" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="Готовность проекта"><span :style="{ width: `${progress}%` }" /></div>
  </div>
</template>
