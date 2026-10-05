<script setup>
import { ref } from 'vue'
import { useProjectStore } from '../stores/project'
import FileUploader from './FileUploader.vue'
const store = useProjectStore()
const selectedFiles = ref([])
const busy = ref(false)
const uploading = ref(false)
async function upload(id) {
  uploading.value = true
  if (await store.uploadAttachments(id, selectedFiles.value)) selectedFiles.value = []
  uploading.value = false
}
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
    <details
      v-if="store.apiMode && task.attachments.length < 10"
      class="detail-block upload-details"
    >
      <summary>Добавить пример или файл</summary>
      <FileUploader v-model="selectedFiles" @busy="busy = $event" />
      <button
        v-if="selectedFiles.length"
        class="button secondary"
        :disabled="busy || uploading"
        @click="upload(task.id)"
      >
        {{ uploading ? 'Загружаем…' : 'Добавить файлы' }}
      </button>
    </details>
  </section>
</template>
