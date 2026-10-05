<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useProjectStore } from '../stores/project'
import { formatDate } from '../constants/project'
import AppIcon from '../components/AppIcon.vue'
import TaskStatus from '../components/TaskStatus.vue'
import TaskPriority from '../components/TaskPriority.vue'
import TaskDetails from '../components/TaskDetails.vue'
import CommentList from '../components/CommentList.vue'
import CommentForm from '../components/CommentForm.vue'
import HistoryTimeline from '../components/HistoryTimeline.vue'
import DeveloperPanel from '../components/DeveloperPanel.vue'
const route = useRoute()
const store = useProjectStore()
const task = computed(() => store.findTask(route.params.id))
</script>
<template>
  <div v-if="task" class="task-page" :key="task.id"><RouterLink to="/" class="back-link"><AppIcon name="arrow" :size="17" />Назад к проекту</RouterLink><header class="task-heading"><div class="task-breadcrumb"><AppIcon name="grid" :size="15" />{{ task.section }}<span>/</span>Детали идеи</div><h1>{{ task.title }}</h1><div class="task-heading-meta"><TaskStatus :status="task.status" /><TaskPriority :priority="task.priority" /><span class="task-created">Добавлена {{ formatDate(task.createdAt) }}</span></div></header>
    <div class="task-layout"><div class="task-main"><TaskDetails :task="task" /><section class="surface comments-panel"><h2>Обсудим детали <span class="count-pill">{{ task.comments.length }}</span></h2><CommentList :comments="task.comments" /><CommentForm :task-id="task.id" /></section></div><aside class="task-sidebar"><section v-if="!store.isDeveloper" class="approval-panel"><span class="approval-icon"><AppIcon :name="task.clientApproved ? 'check' : 'leaf'" :size="26" /></span><h2>{{ task.clientApproved ? 'Всё согласовано' : 'Всё так, как вы хотите?' }}</h2><p>{{ task.clientApproved ? 'Разработчик видит ваше согласование. Все дальнейшие детали можно обсудить ниже.' : 'Если описание верно отражает вашу идею, согласуйте её. Если есть вопросы — напишите в комментариях.' }}</p><div v-if="task.clientApproved" class="approval-success" role="status"><AppIcon name="check" :size="18" />Вы согласовали эту задачу</div><button v-else class="button primary" :disabled="store.storageBlocked" @click="store.approveTask(task.id)"><AppIcon name="check" :size="18" />Согласовать задачу</button></section><DeveloperPanel v-if="store.isDeveloper" :task="task" /><HistoryTimeline :history="task.history" :developer="store.isDeveloper" /></aside></div>
  </div><div v-else class="empty-state page-empty"><AppIcon name="leaf" :size="34" /><h1>Идея не найдена</h1><p>Возможно, она была удалена или ссылка ведёт на другой проект.</p><RouterLink to="/" class="button primary">Вернуться к проекту</RouterLink></div>
</template>
