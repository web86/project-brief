<script setup>
import { t, formatApiError } from '../i18n/index.js'

import { onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { FILE_ACCEPT, MAX_FILES, getPastedImages, prepareAttachment } from '../utils/attachments'
import { formatSize } from '../constants/project'
import { API_MODE } from '../api/client'
import AppIcon from './AppIcon.vue'
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  compact: Boolean,
  disabled: Boolean,
  maxFiles: { type: Number, default: MAX_FILES },
  maxTotalSize: { type: Number, default: Infinity },
})
const emit = defineEmits(['update:modelValue', 'busy'])
const fileInputId = useId()
const input = ref(null)
const dragging = ref(false)
const pending = ref(false)
const errors = ref([])
const pastedNotice = ref('')
let active = true
async function addFiles(files) {
  if (pending.value || props.disabled) return
  errors.value = []
  pending.value = true
  emit('busy', true)
  const next = [...props.modelValue]
  try {
    for (const file of Array.from(files)) {
      if (next.length >= props.maxFiles) {
        errors.value.push(
          props.compact ? t('commentFiles.countLimit') : t('ui.youCanAddUpToFilesToOne'),
        )
        break
      }
      if (!props.compact && next.some((item) => item.name === file.name && item.size === file.size))
        continue
      if (next.reduce((sum, item) => sum + item.size, 0) + file.size > props.maxTotalSize) {
        errors.value.push(t('commentFiles.totalLimit'))
        continue
      }
      try {
        const prepared = await prepareAttachment(file)
        next.push(API_MODE ? { ...prepared, file } : prepared)
      } catch (error) {
        errors.value.push(error.message)
      }
    }
    if (active) emit('update:modelValue', next)
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
  if (event.defaultPrevented || props.disabled || document.querySelector('dialog[open]')) return
  if (!props.compact && event.target?.closest?.('.comment-form')) return
  const images = getPastedImages(event.clipboardData)
  if (!images.length) return
  if (!event.clipboardData?.getData('text/plain')) event.preventDefault()
  event.stopPropagation()
  if (pending.value) {
    errors.value = [t('ui.waitUntilTheSelectedFilesAreProcessedThen')]
    return
  }
  pastedNotice.value = ''
  const previousCount = props.modelValue.length
  addFiles(images).then(() => {
    if (props.modelValue.length > previousCount) pastedNotice.value = t('ui.clipboardImageAdded')
  })
}
defineExpose({ addFiles, paste, drop })
onMounted(() => {
  if (!props.compact) document.addEventListener('paste', paste)
})
onBeforeUnmount(() => {
  active = false
  document.removeEventListener('paste', paste)
})
</script>
<template>
  <div class="file-uploader" :class="{ 'comment-uploader': compact }">
    <button
      v-if="compact"
      class="text-button attach-files"
      type="button"
      :disabled="pending || disabled"
      @click="input.click()"
    >
      <AppIcon name="paperclip" :size="18" />{{ t('commentFiles.attach') }}
    </button>
    <div
      v-if="!compact"
      class="upload-zone"
      :class="{ dragging }"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="drop"
    >
      <span class="upload-icon"><AppIcon name="upload" :size="22" /></span>
      <p>
        <button
          type="button"
          class="text-button"
          :disabled="pending || disabled"
          @click="input.click()"
        >
          {{ pending ? t('ui.processingFiles') : t('ui.chooseFiles') }}
        </button>
        <span>{{ t('ui.orDragThemHere') }}</span>
      </p>
      <span class="paste-hint">{{ t('ui.pasteAScreenshotCtrlVV') }}</span>
      <span class="small muted">{{ t('ui.imagesPdfDocDocxTxtZipUpTo') }}</span
      ><label class="sr-only" :for="compact ? fileInputId : 'idea-files'">{{
        t('ui.chooseExamplesOrScreenshots')
      }}</label>
    </div>
    <input
      :id="compact ? fileInputId : 'idea-files'"
      ref="input"
      class="sr-only"
      type="file"
      :aria-label="t('commentFiles.attach')"
      multiple
      :accept="FILE_ACCEPT"
      :disabled="pending || disabled"
      @change="addFiles($event.target.files)"
    />
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
                ? t('ui.originalFile')
                : file.preview
                  ? t('ui.previewWillBeSaved')
                  : t('ui.nameAndSize')
            }}</span
          >
        </div>
        <button
          type="button"
          class="icon-button"
          :disabled="pending || disabled"
          :aria-label="t('ui.remove', { arg0: file.name })"
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
    <p v-for="error in errors" :key="error" class="field-error" role="alert">
      {{ formatApiError(error) }}
    </p>
    <p v-if="!compact" class="field-help">
      {{
        API_MODE
          ? t('ui.filesAreOnlyAvailableToProjectParticipants')
          : t('ui.imagesShowPreviewsOtherFilesShowTheirName')
      }}
    </p>
    <span class="sr-only" role="status" aria-live="polite">{{ pastedNotice }}</span>
  </div>
</template>
