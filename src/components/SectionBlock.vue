<script setup>
import { computed, ref, watch } from 'vue'
import { readSectionCollapsed, writeSectionCollapsed } from '../utils/sectionCollapse.js'
import TaskCard from './TaskCard.vue'
import { pluralize } from '../constants/project.js'
import { t } from '../i18n/index.js'
import { useProjectStore } from '../stores/project.js'
const props = defineProps({
  id: String,
  name: String,
  position: Number,
  tasks: Array,
  totalCount: Number,
})
const store = useProjectStore()
const collapsed = ref(false)
const contentId = computed(() => `section-tasks-${props.id}`)
const count = computed(() => props.totalCount ?? props.tasks.length)
watch(
  () => [store.project.id, props.id],
  ([projectId, sectionId]) => {
    collapsed.value = readSectionCollapsed(projectId, sectionId)
  },
  { immediate: true },
)
function toggle() {
  collapsed.value = !collapsed.value
  writeSectionCollapsed(store.project.id, props.id, collapsed.value)
}
</script>
<template>
  <section class="section-block" :class="{ 'empty-section': !tasks.length }" :aria-label="name">
    <div class="section-heading">
      <h2 :title="name">
        <button
          type="button"
          class="section-toggle"
          :aria-expanded="!collapsed"
          :aria-controls="contentId"
          @click="toggle"
        >
          <span class="section-chevron" aria-hidden="true">{{ collapsed ? '▸' : '▾' }}</span>
          <span class="section-title">{{ position }}. {{ name }}</span>
          <span class="section-count">{{ count }} {{ pluralize(count) }}</span>
        </button>
      </h2>
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
    <div :id="contentId" v-show="!collapsed">
      <div v-if="tasks.length" class="task-grid">
        <TaskCard v-for="task in tasks" :key="task.id" :task="task" />
      </div>
      <p v-else class="empty-section-copy">{{ t('flow.emptySection') }}</p>
    </div>
  </section>
</template>
