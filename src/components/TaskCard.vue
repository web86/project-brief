<script setup>
import { t } from '../i18n/index.js'

import { computed } from 'vue'
import { useProjectStore } from '../stores/project'
const store = useProjectStore()
import AppIcon from './AppIcon.vue'
import TaskStatus from './TaskStatus.vue'
import TaskPriority from './TaskPriority.vue'
import { getTaskLocation } from '../constants/project'
const props = defineProps({ task: { type: Object, required: true } })
const canonicalIndex = computed(() =>
  store.sectionTasks(props.task.sectionId).findIndex((task) => task.id === props.task.id),
)
</script>
<template>
  <article class="task-card-shell" :class="{ 'has-card-controls': store.isDeveloper }">
    <RouterLink
      :to="store.taskPath(task.id)"
      class="task-card"
      :class="{ 'is-done': task.status === 'done' }"
    >
      <div class="card-top">
        <TaskStatus :status="task.status" /><AppIcon class="card-arrow" name="chevron" :size="16" />
      </div>
      <span class="brief-number">{{ store.numberForTask(task) }}</span>
      <h3>{{ task.title }}</h3>
      <p class="card-description">{{ task.description }}</p>
      <div class="card-section" :title="getTaskLocation(task)">{{ getTaskLocation(task) }}</div>
      <div class="card-footer">
        <TaskPriority :priority="task.priority" />
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
    <div v-if="store.isDeveloper" class="card-order-actions">
      <button
        v-if="canonicalIndex > 0"
        class="order-icon"
        type="button"
        :aria-label="t('ui.moveIdeaUp') + ': ' + task.title"
        :disabled="store.orderPending || store.storageBlocked"
        @click="store.reorderTask(task.id, 'up')"
      >
        ↑
      </button>
      <button
        v-if="canonicalIndex < store.sectionTasks(task.sectionId).length - 1"
        class="order-icon"
        type="button"
        :aria-label="t('ui.moveIdeaDown') + ': ' + task.title"
        :disabled="store.orderPending || store.storageBlocked"
        @click="store.reorderTask(task.id, 'down')"
      >
        ↓
      </button>
    </div>
  </article>
</template>
