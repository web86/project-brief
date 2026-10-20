<script setup>
import { t, formatCurrency, intlLocale } from '../i18n/index.js'

import { useProjectStore } from '../stores/project'
const store = useProjectStore()
import AppIcon from './AppIcon.vue'
import TaskStatus from './TaskStatus.vue'
import TaskPriority from './TaskPriority.vue'
import { getTaskLocation } from '../constants/project'
const props = defineProps({ task: { type: Object, required: true } })
const filled = (value) => value !== null && value !== undefined && value !== ''
</script>
<template>
  <article class="task-card-shell">
    <RouterLink
      :to="store.taskPath(task.id)"
      class="task-card"
      :class="{ 'is-done': task.status === 'done' }"
    >
      <div class="card-top">
        <TaskStatus :status="task.status" /><AppIcon
          v-if="!store.isDeveloper"
          class="card-arrow"
          name="chevron"
          :size="16"
        />
      </div>
      <span class="brief-number">{{ store.numberForTask(task) }}</span>
      <h3>{{ task.title }}</h3>
      <p class="card-description">{{ task.description }}</p>
      <div class="card-section" :title="getTaskLocation(task)">{{ getTaskLocation(task) }}</div>
      <div class="card-footer">
        <div class="card-priority-details">
          <TaskPriority :priority="task.priority" />
          <span v-if="store.isDeveloper && filled(task.estimateHours)" class="card-estimate"
            ><AppIcon name="clock" :size="14" />{{
              Number(task.estimateHours).toLocaleString(intlLocale())
            }}
            {{ t('ui.h') }}</span
          >
          <span
            v-if="store.isDeveloper && filled(task.price)"
            class="card-price"
            tabindex="0"
            :aria-label="formatCurrency(task.price, store.project)"
          >
            <AppIcon name="money" :size="15" />
            <span class="card-price-tooltip" role="tooltip">{{
              formatCurrency(task.price, store.project)
            }}</span>
          </span>
        </div>
        <div class="card-meta">
          <span
            v-if="task.attachments.length"
            :aria-label="`${task.attachments.length} ${t('counts.files', task.attachments.length)}`"
            ><AppIcon name="paperclip" :size="15" />{{ task.attachments.length }}</span
          ><span
            :aria-label="`${task.comments.length} ${t('counts.comments', task.comments.length)}`"
            ><AppIcon name="comment" :size="15" />{{ task.comments.length }}</span
          >
        </div>
      </div>
      <div v-if="task.status === 'done'" class="card-done-overlay" aria-hidden="true">
        <strong><AppIcon name="check" :size="30" />{{ t('ui.done') }}</strong>
        <span>{{ t('ui.open2') }}</span>
      </div>
    </RouterLink>
  </article>
</template>
