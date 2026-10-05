<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { FILE_ACCEPT, MAX_FILES, getPastedImages, prepareAttachment } from '../utils/attachments'
import { formatSize } from '../constants/project'
import { API_MODE } from '../api/client'
import AppIcon from './AppIcon.vue'
const props = defineProps({ modelValue: { type: Array, default: () => [] } })
const emit = defineEmits(['update:modelValue', 'busy'])
const input = ref(null)
const dragging = ref(false)
const pending = ref(false)
const errors = ref([])
const pastedNotice = ref('')
async function addFiles(files) {
  if (pending.value) return
  errors.value = []
  pending.value = true
  emit('busy', true)
  const next = [...props.modelValue]
  try {
    for (const file of Array.from(files)) {
      if (next.length >= MAX_FILES) {
        errors.value.push('К одной идее можно добавить до 10 файлов.')
        break
      }
      if (next.some((item) => item.name === file.name && item.size === file.size)) continue
      try {
        const prepared = await prepareAttachment(file)
        next.push(API_MODE ? { ...prepared, file } : prepared)
      } catch (error) {
        errors.value.push(error.message)
      }
    }
    emit('update:modelValue', next)
  } finally {
    pending.value = false
    emit('busy', false)
    if (input.value) input.value.value = ''
  }
}
function drop(event) {
  dragging.value = false
  addFiles(event.dataTransfer.files)
}
function paste(event) {
  if (event.defaultPrevented || document.querySelector('dialog[open]')) return
  const images = getPastedImages(event.clipboardData)
  if (!images.length) return
  event.preventDefault()
  if (pending.value) {
    errors.value = ['Подождите, пока обработаются выбранные файлы, и вставьте изображение ещё раз.']
    return
  }
  pastedNotice.value = ''
  const previousCount = props.modelValue.length
  addFiles(images).then(() => {
    if (props.modelValue.length > previousCount)
      pastedNotice.value = 'Изображение из буфера добавлено.'
  })
}
onMounted(() => document.addEventListener('paste', paste))
onBeforeUnmount(() => document.removeEventListener('paste', paste))
</script>
<template>
  <div class="file-uploader">
    <div
      class="upload-zone"
      :class="{ dragging }"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="drop"
    >
      <span class="upload-icon"><AppIcon name="upload" :size="22" /></span>
      <p>
        <button type="button" class="text-button" :disabled="pending" @click="input.click()">
          {{ pending ? 'Обрабатываем файлы…' : 'Выберите файлы' }}
        </button>
        <span>или перетащите сюда</span>
      </p>
      <span class="paste-hint">Скриншот можно вставить: Ctrl+V / ⌘V</span>
      <span class="small muted">Изображения, PDF, DOC, DOCX, TXT, ZIP · до 20 МБ</span
      ><label class="sr-only" for="idea-files">Выберите примеры или скриншоты</label
      ><input
        id="idea-files"
        ref="input"
        class="sr-only"
        type="file"
        multiple
        :accept="FILE_ACCEPT"
        :disabled="pending"
        @change="addFiles($event.target.files)"
      />
    </div>
    <ul v-if="modelValue.length" class="file-list">
      <li v-for="file in modelValue" :key="file.id">
        <img v-if="file.preview" :src="file.preview" alt="" /><span v-else class="file-symbol"
          ><AppIcon name="file"
        /></span>
        <div>
          <strong>{{ file.name }}</strong
          ><span
            >{{ formatSize(file.size)
            }}{{
              API_MODE
                ? ' · исходный файл'
                : file.preview
                  ? ' · превью сохранится'
                  : ' · название и размер'
            }}</span
          >
        </div>
        <button
          type="button"
          class="icon-button"
          :disabled="pending"
          :aria-label="`Убрать ${file.name}`"
          @click="
            emit(
              'update:modelValue',
              modelValue.filter((item) => item.id !== file.id),
            )
          "
        >
          <AppIcon name="close" :size="17" />
        </button>
      </li>
    </ul>
    <p v-for="error in errors" :key="error" class="field-error" role="alert">{{ error }}</p>
    <p class="field-help">
      {{
        API_MODE
          ? 'Файлы будут доступны только участникам проекта.'
          : 'Изображения — превью; остальные файлы — название и размер.'
      }}
    </p>
    <span class="sr-only" role="status" aria-live="polite">{{ pastedNotice }}</span>
  </div>
</template>
