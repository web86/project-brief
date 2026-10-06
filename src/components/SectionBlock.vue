<script setup>
import TaskCard from './TaskCard.vue'
import AppIcon from './AppIcon.vue'
import { pluralize } from '../constants/project.js'
import { t } from '../i18n/index.js'
import { useProjectStore } from '../stores/project.js'
defineProps({ id: String, name: String, position: Number, tasks: Array })
const store = useProjectStore()
</script>
<template>
  <section class="section-block" :class="{ 'empty-section': !tasks.length }" :aria-label="name">
    <div class="section-heading">
      <span class="section-icon"><AppIcon name="grid" :size="18" /></span>
      <h2 :title="name">{{ position }}. {{ name }}</h2>
      <span class="section-count">{{ tasks.length }} {{ pluralize(tasks.length) }}</span>
      <div v-if="store.isDeveloper" class="section-order-actions">
        <button
          v-if="position > 1"
          type="button"
          class="order-icon"
          :aria-label="t('ui.moveSectionUp', { arg0: name })"
          :disabled="store.orderPending || store.storageBlocked"
          @click="store.reorderSection(id, 'up')"
        >
          ↑
        </button>
        <button
          v-if="position < store.sections.length"
          type="button"
          class="order-icon"
          :aria-label="t('ui.moveSectionDown', { arg0: name })"
          :disabled="store.orderPending || store.storageBlocked"
          @click="store.reorderSection(id, 'down')"
        >
          ↓
        </button>
      </div>
    </div>
    <div v-if="tasks.length" class="task-grid">
      <TaskCard v-for="task in tasks" :key="task.id" :task="task" />
    </div>
    <p v-else class="empty-section-copy">{{ t('flow.emptySection') }}</p>
  </section>
</template>
