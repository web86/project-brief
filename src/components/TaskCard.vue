<script setup>
import AppIcon from './AppIcon.vue'
import TaskStatus from './TaskStatus.vue'
import TaskPriority from './TaskPriority.vue'
defineProps({ task: { type: Object, required: true } })
</script>
<template>
  <RouterLink :to="`/task/${task.id}`" class="task-card">
    <div class="card-top">
      <TaskStatus :status="task.status" /><AppIcon class="card-arrow" name="chevron" :size="16" />
    </div>
    <h3>{{ task.title }}</h3>
    <p class="card-description">{{ task.description }}</p>
    <div class="card-section">{{ task.section }}</div>
    <div class="card-footer">
      <TaskPriority :priority="task.priority" />
      <div class="card-meta">
        <span v-if="task.attachments.length" :aria-label="`Вложений: ${task.attachments.length}`"
          ><AppIcon name="paperclip" :size="15" />{{ task.attachments.length }}</span
        ><span :aria-label="`Комментариев: ${task.comments.length}`"
          ><AppIcon name="comment" :size="15" />{{ task.comments.length }}</span
        >
      </div>
    </div>
  </RouterLink>
</template>
