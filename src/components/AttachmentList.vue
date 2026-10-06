<script setup>
import { t } from '../i18n/index.js'

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
      ><button type="button" v-if="file.preview" class="attachment-item" @click="selected = file">
        <img :src="file.preview" alt="" /><span
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
    id="attachment-dialog-title"
    @close="selected = null"
    ><img v-if="selected" class="attachment-preview" :src="selected.preview" :alt="selected.name" />
    <a v-if="selected?.downloadUrl" :href="selected.downloadUrl" class="button secondary">{{
      t('ui.downloadOriginalFile')
    }}</a>
    <p v-else class="field-help">
      {{ t('ui.reducedPreviewTheOriginalFileIsNotSaved') }}
    </p></BaseDialog
  >
</template>
