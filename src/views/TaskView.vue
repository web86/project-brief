<script setup>
import { t, formatApiError } from '../i18n/index.js'

import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useProjectStore } from '../stores/project'
import { getTaskLocation } from '../constants/project'
import AppIcon from '../components/AppIcon.vue'
import TaskStatus from '../components/TaskStatus.vue'
import TaskPriority from '../components/TaskPriority.vue'
import TaskDetails from '../components/TaskDetails.vue'
import CommentList from '../components/CommentList.vue'
import CommentForm from '../components/CommentForm.vue'
import HistoryTimeline from '../components/HistoryTimeline.vue'
import DeveloperTaskPanel from '../components/DeveloperTaskPanel.vue'
import BaseDialog from '../components/BaseDialog.vue'
import LoadingButton from '../components/LoadingButton.vue'
import { canEditClientTask, useDraftDeletion } from '../utils/clientDraft.js'
import DeveloperNotes from '../components/DeveloperNotes.vue'
const route = useRoute()
const storeRouter = useRouter()
const store = useProjectStore()
const task = computed(() => store.findTask(route.params.id))
const commentForm = ref(null)
const approving = ref(false)
const editable = computed(() => !store.isDeveloper && canEditClientTask(task.value))
const deletion = useDraftDeletion({ store, task, router: storeRouter })
const deleteOpen = deletion.open
const deleting = deletion.pending
async function approve() {
  if (approving.value) return
  approving.value = true
  await store.approveTask(task.value.id)
  approving.value = false
}
function focusClarification() {
  commentForm.value?.focusForClarification()
}
</script>
<template>
  <div v-if="task" class="task-page" :key="task.id">
    <RouterLink :to="store.projectPath" class="back-link"
      ><AppIcon name="arrow" :size="17" />{{ t('ui.backToTheProject2') }}</RouterLink
    >
    <header class="task-heading">
      <p class="brief-number">{{ t('flow.ideaNumber', { number: store.numberForTask(task) }) }}</p>
      <h1>{{ task.title }}</h1>
      <p class="task-section-name">{{ store.taskSection(task)?.name }}</p>
      <p class="task-location" :title="getTaskLocation(task)">{{ getTaskLocation(task) }}</p>
      <div class="task-heading-row">
        <div class="task-heading-meta">
          <TaskStatus :status="task.status" /><TaskPriority :priority="task.priority" />
        </div>
        <div v-if="!store.isDeveloper" class="task-approval">
          <span v-if="task.clientApproved" class="approval-success" role="status"
            ><AppIcon name="check" :size="18" />{{ t('ui.approvedByTheClient') }}</span
          >
          <LoadingButton
            :busy="approving"
            v-else
            class="button primary"
            :disabled="store.storageBlocked || approving"
            @click="approve"
          >
            <AppIcon name="check" :size="18" />{{ t('ui.approveIdea') }}
          </LoadingButton>
        </div>
      </div>
    </header>
    <div v-if="editable" class="client-draft-actions">
      <RouterLink :to="`${store.taskPath(task.id)}/edit`" class="text-button">{{
        t('ui.edit')
      }}</RouterLink>
      <button
        type="button"
        class="text-button delete-idea-action"
        @click="deletion.requestDeletion"
      >
        {{ t('flow.deleteIdea') }}
      </button>
    </div>
    <p v-else-if="!store.isDeveloper" class="brief-lock-copy">
      {{
        t(
          task.clientApproved || task.status === 'approved'
            ? 'flow.briefLocked'
            : 'flow.workLocked',
        )
      }}
    </p>
    <BaseDialog
      :open="deleteOpen"
      :title="t('flow.deleteTitle')"
      id="delete-idea-title"
      @close="deletion.cancel"
    >
      <p>{{ t('flow.deleteBody') }}</p>
      <p v-if="store.apiError" class="field-error" role="alert">
        {{ formatApiError(store.apiError) }}
      </p>
      <template #footer
        ><button
          type="button"
          class="button secondary"
          :disabled="deleting"
          @click="deletion.cancel"
        >
          {{ t('ui.cancel') }}</button
        ><LoadingButton class="button danger" :busy="deleting" @click="deletion.confirm">{{
          t('ui.delete')
        }}</LoadingButton></template
      >
    </BaseDialog>
    <div class="task-layout" :class="{ 'has-developer': store.isDeveloper }">
      <TaskDetails :task="task" />
      <div class="task-sidebar">
        <DeveloperTaskPanel v-if="store.isDeveloper" :task="task" @clarify="focusClarification" />
        <section class="surface comments-panel">
          <h2>
            {{ t('ui.comments2') }} <span class="count-pill">{{ task.comments.length }}</span>
          </h2>
          <CommentList :comments="task.comments" /><CommentForm
            ref="commentForm"
            :task-id="task.id"
          />
        </section>
      </div>
      <DeveloperNotes v-if="store.isDeveloper" :task="task" />
      <HistoryTimeline :history="task.history" :developer="store.isDeveloper" />
    </div>
  </div>
  <div v-else class="empty-state page-empty">
    <h1>{{ t('ui.ideaNotFound') }}</h1>
    <p>{{ t('ui.itMayHaveBeenDeletedOrTheLink') }}</p>
    <RouterLink :to="store.projectPath" class="button primary">{{
      t('ui.backToTheProject')
    }}</RouterLink>
  </div>
</template>
