<script setup>
import { ref } from 'vue'
import { t } from '../i18n/index.js'
import { useProjectStore } from '../stores/project.js'
import AppIcon from './AppIcon.vue'
import BaseDialog from './BaseDialog.vue'
import LoadingButton from './LoadingButton.vue'
const store = useProjectStore()
const open = ref(false)
const loading = ref(false)
async function show() {
  if (loading.value) return
  loading.value = true
  try {
    if (await store.loadTrash()) open.value = true
  } finally {
    loading.value = false
  }
}
</script>
<template>
  <LoadingButton class="text-button project-trash-trigger" :busy="loading" @click="show"
    ><AppIcon name="trash" :size="17" />{{ t('management.trash') }}</LoadingButton
  >
  <BaseDialog
    :open="open"
    :title="t('management.trash')"
    id="project-trash-title"
    @close="!store.orderPending && (open = false)"
  >
    <p>{{ t('management.trashHelp') }}</p>
    <p v-if="!store.trash.length" class="empty-section-copy">{{ t('management.trashEmpty') }}</p>
    <ul v-else class="trash-list">
      <li v-for="task in store.trash" :key="task.id">
        <div>
          <strong>{{ task.title }}</strong
          ><span>{{
            task.section?.name ||
            store.sections.find((section) => section.id === task.sectionId)?.name
          }}</span>
        </div>
        <LoadingButton
          class="button secondary"
          :busy="store.orderPending"
          :disabled="store.orderPending"
          @click="store.restoreTask(task.id)"
          >{{ t('management.restore') }}</LoadingButton
        >
      </li>
    </ul>
  </BaseDialog>
</template>
