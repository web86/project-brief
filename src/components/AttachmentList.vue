<script setup>
import { t } from '../i18n/index.js'

import { ref, useId, watch } from 'vue'
import { formatSize } from '../constants/project'
import BaseDialog from './BaseDialog.vue'
import AppIcon from './AppIcon.vue'
const props = defineProps({ attachments: Array, compact: Boolean })
const selected = ref(null)
const dialogId = useId()
const zoomed = ref(false)
watch(
  () => props.attachments,
  () => {
    selected.value = null
  },
)
watch(
  () => selected.value,
  () => {
    zoomed.value = false
  },
)
</script>
<template>
  <div
    v-if="attachments.length"
    class="attachment-list"
    :class="{ 'comment-attachments': compact }"
  >
    <template v-for="file in attachments" :key="file.id"
      ><button type="button" v-if="file.preview" class="attachment-item" @click="selected = file">
        <img :src="file.preview" alt="" loading="lazy" decoding="async" /><span
          ><strong>{{ file.name }}</strong
          ><small>{{ formatSize(file.size) }} {{ t('ui.view') }}</small></span
        ><AppIcon name="external" :size="16" />
      </button>
      <a v-else-if="file.downloadUrl" class="attachment-item" :href="file.downloadUrl">
        <span class="file-symbol"><AppIcon name="file" /></span
        ><span
          ><strong>{{ file.name }}</strong
          ><small>{{ formatSize(file.size) }} {{ t('ui.download') }}</small></span
        >
      </a>
      <div v-else class="attachment-item">
        <span class="file-symbol"><AppIcon name="file" /></span
        ><span
          ><strong>{{ file.name }}</strong
          ><small>{{ formatSize(file.size) }} {{ t('ui.fileDetailsOnly') }}</small></span
        >
      </div></template
    >
  </div>
  <BaseDialog
    :open="!!selected"
    :title="selected?.name || t('ui.imagePreview')"
    :id="dialogId"
    @close="selected = null"
    ><div v-if="selected" class="attachment-viewport" :class="{ zoomed }">
      <button
        type="button"
        class="image-zoom"
        :aria-label="t('commentFiles.zoom')"
        :aria-pressed="zoomed"
        @click="zoomed = !zoomed"
      >
        <img
          class="attachment-preview"
          :src="selected.originalUrl || selected.preview"
          :alt="selected.name"
        />
      </button>
    </div>
    <a v-if="selected?.downloadUrl" :href="selected.downloadUrl" class="button secondary">{{
      t('ui.downloadOriginalFile')
    }}</a>
    <p v-else class="field-help">
      {{ t('ui.reducedPreviewTheOriginalFileIsNotSaved') }}
    </p></BaseDialog
  >
</template>
