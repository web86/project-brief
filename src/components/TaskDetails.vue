<script setup>
import AttachmentList from './AttachmentList.vue'
defineProps({ task: Object })
</script>
<template>
  <section class="surface details-surface">
    <div class="detail-block">
      <h2>Что хочется изменить</h2>
      <p class="prose">{{ task.description }}</p>
    </div>
    <div class="detail-block">
      <h2>Что должно получиться</h2>
      <p v-if="task.expectedResult" class="prose expected-result">{{ task.expectedResult }}</p>
      <p v-else class="muted">Не указан.</p>
    </div>
    <div v-if="task.attachments.length" class="detail-block">
      <h2>
        Примеры и файлы <span class="count-pill">{{ task.attachments.length }}</span>
      </h2>
      <AttachmentList :attachments="task.attachments" />
    </div>
  </section>
</template>
