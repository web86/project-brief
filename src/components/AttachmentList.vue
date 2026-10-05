<script setup>
import { ref } from 'vue'
import { formatSize } from '../constants/project'
import BaseDialog from './BaseDialog.vue'
import AppIcon from './AppIcon.vue'
defineProps({ attachments: Array })
const selected = ref(null)
</script>
<template>
  <div v-if="attachments.length" class="attachment-list">
    <template v-for="file in attachments" :key="file.id"
      ><button v-if="file.preview" class="attachment-item" @click="selected = file">
        <img :src="file.preview" alt="" /><span
          ><strong>{{ file.name }}</strong
          ><small>{{ formatSize(file.size) }} · посмотреть</small></span
        ><AppIcon name="external" :size="16" />
      </button>
      <div v-else class="attachment-item">
        <span class="file-symbol"><AppIcon name="file" /></span
        ><span
          ><strong>{{ file.name }}</strong
          ><small>{{ formatSize(file.size) }} · только сведения о файле</small></span
        >
      </div></template
    >
  </div>
  <p v-else class="muted small">К этой идее пока нет вложений.</p>
  <p v-if="attachments.length" class="field-help">
    Оригиналы файлов не загружены. Изображения доступны в виде уменьшенных превью.
  </p>
  <BaseDialog
    :open="!!selected"
    :title="selected?.name || 'Превью изображения'"
    id="attachment-dialog-title"
    @close="selected = null"
    ><img v-if="selected" class="attachment-preview" :src="selected.preview" :alt="selected.name" />
    <p class="field-help">Уменьшенное превью. Исходный файл не сохраняется.</p></BaseDialog
  >
</template>
