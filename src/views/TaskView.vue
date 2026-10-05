<script setup>
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
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
import DeveloperNotes from '../components/DeveloperNotes.vue'
const route = useRoute()
const store = useProjectStore()
const task = computed(() => store.findTask(route.params.id))
const commentForm = ref(null)
function focusClarification() {
  commentForm.value?.focusForClarification()
}
</script>
<template>
  <div v-if="task" class="task-page" :key="task.id">
    <RouterLink to="/" class="back-link"
      ><AppIcon name="arrow" :size="17" />Назад к проекту</RouterLink
    >
    <header class="task-heading">
      <h1>{{ task.title }}</h1>
      <p class="task-location" :title="getTaskLocation(task)">{{ getTaskLocation(task) }}</p>
      <div class="task-heading-row">
        <div class="task-heading-meta">
          <TaskStatus :status="task.status" /><TaskPriority :priority="task.priority" />
        </div>
        <div v-if="!store.isDeveloper" class="task-approval">
          <span v-if="task.clientApproved" class="approval-success" role="status"
            ><AppIcon name="check" :size="18" />Согласовано клиентом</span
          >
          <button
            v-else
            class="button primary"
            :disabled="store.storageBlocked"
            @click="store.approveTask(task.id)"
          >
            <AppIcon name="check" :size="18" />Согласовать задачу
          </button>
        </div>
      </div>
    </header>
    <div class="task-layout" :class="{ 'has-developer': store.isDeveloper }">
      <TaskDetails :task="task" />
      <div class="task-sidebar">
        <DeveloperTaskPanel v-if="store.isDeveloper" :task="task" @clarify="focusClarification" />
        <section class="surface comments-panel">
          <h2>
            Комментарии <span class="count-pill">{{ task.comments.length }}</span>
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
    <h1>Идея не найдена</h1>
    <p>Возможно, она была удалена или ссылка ведёт на другой проект.</p>
    <RouterLink to="/" class="button primary">Вернуться к проекту</RouterLink>
  </div>
</template>
