<script setup>
import { useDiscussionsStore } from '../stores/discussions.js'
import { toast } from '../services/toast.js'
import { t, formatApiError } from '../i18n/index.js'

import { computed, ref, onMounted, onUnmounted, watch } from 'vue'
import { bindDeveloperEditor } from '../utils/developerEditor.js'
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
const seen = new Set()
let readTimer
function seenComment(id) {
  if (!store.apiMode || !store.isDeveloper || storeRouter.navigationPending?.value) return
  seen.add(id)
  clearTimeout(readTimer)
  const projectId = route.params.uuid
  readTimer = setTimeout(async () => {
    if (storeRouter.navigationPending?.value) {
      seen.clear()
      return
    }
    const ids = [...seen]
    seen.clear()
    try {
      await useDiscussionsStore().mark(projectId, ids)
    } catch (error) {
      toast.error(() => formatApiError(error))
    }
  }, 150)
}
watch(
  () => route.params.id,
  () => {
    clearTimeout(readTimer)
    seen.clear()
  },
)
onUnmounted(() => clearTimeout(readTimer))
const task = computed(() => store.findTask(route.params.id))
const editorTask = computed(() => store.developerTask(route.params.id))
const dirty = computed(() => store.isDeveloper && store.hasDeveloperChanges(route.params.id))
let editor
onMounted(() => {
  editor = bindDeveloperEditor({
    active: () => store.isDeveloper && !!task.value,
    dirty: () => dirty.value,
    save: () => store.saveDeveloperData(route.params.id),
  })
  editor.sync()
})
watch([dirty, () => store.isDeveloper], () => editor?.sync(), { flush: 'sync' })
onUnmounted(() => editor?.dispose())
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
  <div v-if="task" class="task-workspace">
    <div class="task-page" :key="task.id">
      <div class="task-context-row">
        <div class="task-context-left">
          <span class="task-context-location" :title="getTaskLocation(task)"
            ><AppIcon name="pin" :size="16" /><span
              >{{ store.taskSection(task)?.name
              }}<template v-if="getTaskLocation(task) !== store.taskSection(task)?.name">
                · {{ getTaskLocation(task) }}</template
              ></span
            ></span
          >
          <TaskPriority :priority="task.priority" />
        </div>
        <TaskStatus :status="task.status" />
      </div>
      <header class="task-heading">
        <h1>
          <span
            class="task-title-number"
            :aria-label="t('flow.ideaNumber', { number: store.numberForTask(task) })"
            >#{{ store.numberForTask(task) }}</span
          >{{ task.title }}
        </h1>
        <div v-if="!store.isDeveloper" class="task-approval">
          <span v-if="task.clientApproved" class="approval-success" role="status"
            ><AppIcon name="check" :size="18" />{{ t('ui.approvedByTheClient') }}</span
          >
          <LoadingButton
            v-else
            :busy="approving"
            class="button primary"
            :disabled="store.storageBlocked || approving"
            @click="approve"
            ><AppIcon name="check" :size="18" />{{ t('ui.approveIdea') }}</LoadingButton
          >
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
        <div class="task-main-column">
          <TaskDetails :task="task" />
          <DeveloperNotes v-if="store.isDeveloper" :task="editorTask" />
          <section class="surface comments-panel">
            <h2>
              {{ t('ui.comments2') }} <span class="count-pill">{{ task.comments.length }}</span>
            </h2>
            <CommentList
              :comments="task.comments"
              :target="route.hash.slice(1)"
              @seen="seenComment"
            />
            <CommentForm ref="commentForm" :task-id="task.id" />
          </section>
        </div>
        <div class="task-sidebar">
          <DeveloperTaskPanel
            v-if="store.isDeveloper"
            :task="editorTask"
            @clarify="focusClarification"
          />
          <HistoryTimeline :history="task.history" :developer="store.isDeveloper" />
        </div>
      </div>
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
