<script setup>
import LoadingButton from './LoadingButton.vue'
import { t } from '../i18n/index.js'

import { canEditClientTask } from '../utils/clientDraft.js'
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
      <h2>{{ t('ui.whatYouWouldLikeToChange') }}</h2>
      <p class="prose">{{ task.description }}</p>
    </div>
    <div class="detail-block">
      <h2>{{ t('ui.whatTheResultShouldLookLike') }}</h2>
      <p v-if="task.expectedResult" class="prose expected-result">{{ task.expectedResult }}</p>
      <p v-else class="muted">{{ t('ui.notSpecified') }}</p>
    </div>
    <div v-if="task.attachments.length" class="detail-block">
      <h2>
        {{ t('ui.examplesAndFiles') }} <span class="count-pill">{{ task.attachments.length }}</span>
      </h2>
      <AttachmentList :attachments="task.attachments" />
    </div>
    <details
      v-if="
        store.apiMode &&
        task.attachments.length < 10 &&
        (store.isDeveloper || canEditClientTask(task))
      "
      class="detail-block upload-details"
    >
      <summary>{{ t('ui.addAnExampleOrFile') }}</summary>
      <FileUploader v-model="selectedFiles" @busy="busy = $event" />
      <LoadingButton
        :busy="uploading"
        v-if="selectedFiles.length"
        class="button secondary"
        :disabled="busy || uploading"
        @click="upload(task.id)"
      >
        {{ t('ui.addFiles') }}
      </LoadingButton>
    </details>
  </section>
</template>
