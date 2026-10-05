<script setup>
import { ref } from 'vue'
import { FILE_ACCEPT, MAX_FILES, prepareAttachment } from '../utils/attachments'
import { formatSize } from '../constants/project'
import AppIcon from './AppIcon.vue'
const props = defineProps({ modelValue: { type: Array, default: () => [] } })
const emit = defineEmits(['update:modelValue', 'busy'])
const input = ref(null)
const dragging = ref(false)
const pending = ref(false)
const errors = ref([])
async function addFiles(files) {
  if (pending.value) return
  errors.value = []
  pending.value = true
  emit('busy', true)
  const next = [...props.modelValue]
  try {
    for (const file of Array.from(files)) {
      if (next.length >= MAX_FILES) { errors.value.push('К одной идее можно добавить до 10 файлов.'); break }
      if (next.some((item) => item.name === file.name && item.size === file.size)) continue
      try { next.push(await prepareAttachment(file)) } catch (error) { errors.value.push(error.message) }
    }
    emit('update:modelValue', next)
  } finally {
    pending.value = false
    emit('busy', false)
    if (input.value) input.value.value = ''
  }
}
function drop(event) { dragging.value = false; addFiles(event.dataTransfer.files) }
</script>
<template>
  <div class="file-uploader"><div class="upload-zone" :class="{ dragging }" @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="drop"><span class="upload-icon"><AppIcon name="upload" :size="22" /></span><p><button type="button" class="text-button" :disabled="pending" @click="input.click()">{{ pending ? 'Обрабатываем файлы…' : 'Выберите файлы' }}</button> <span>или перетащите их сюда</span></p><span class="small muted">Изображения, PDF, DOC, DOCX, TXT, ZIP · до 20 МБ</span><label class="sr-only" for="idea-files">Выберите примеры или скриншоты</label><input id="idea-files" ref="input" class="sr-only" type="file" multiple :accept="FILE_ACCEPT" :disabled="pending" @change="addFiles($event.target.files)" /></div>
    <ul v-if="modelValue.length" class="file-list"><li v-for="file in modelValue" :key="file.id"><img v-if="file.preview" :src="file.preview" alt="" /><span v-else class="file-symbol"><AppIcon name="file" /></span><div><strong>{{ file.name }}</strong><span>{{ formatSize(file.size) }}{{ file.preview ? ' · превью сохранится' : ' · название и размер' }}</span></div><button type="button" class="icon-button" :disabled="pending" :aria-label="`Убрать ${file.name}`" @click="emit('update:modelValue', modelValue.filter((item) => item.id !== file.id))"><AppIcon name="close" :size="17" /></button></li></ul>
    <p v-for="error in errors" :key="error" class="field-error" role="alert">{{ error }}</p><p class="field-help">Для изображений сохраняется уменьшенное превью. Для остальных файлов — название и размер. Оригиналы пока не загружаются.</p>
  </div>
</template>
