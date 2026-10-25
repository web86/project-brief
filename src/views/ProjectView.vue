<script setup>
import { t } from '../i18n/index.js'

import { computed, provide, ref } from 'vue'
import { useTaskDrag, taskDragKey } from '../utils/taskDrag.js'
import ProjectTrash from '../components/ProjectTrash.vue'
import { useProjectStore } from '../stores/project'
import { FILTERS } from '../constants/project'
import { groupBriefTasks } from '../utils/structure'
import ProjectHeader from '../components/ProjectHeader.vue'
import SectionBlock from '../components/SectionBlock.vue'
import AppIcon from '../components/AppIcon.vue'

const store = useProjectStore()
const activeFilter = ref('all')
const activeSection = ref('')
const dragEnabled = computed(
  () => store.isDeveloper && activeFilter.value === 'all' && !activeSection.value,
)
const drag = useTaskDrag(store, dragEnabled)
provide(taskDragKey, drag)
function resetFilters() {
  activeFilter.value = 'all'
  activeSection.value = ''
}
const groups = computed(() =>
  groupBriefTasks(
    store.tasks,
    store.sections,
    FILTERS.find((filter) => filter.key === activeFilter.value).statuses,
    activeSection.value,
  ),
)
</script>
<template>
  <div class="dashboard">
    <ProjectHeader />
    <div class="ideas-heading">
      <h2>{{ t('ui.ideasAndChanges') }}</h2>
      <ProjectTrash v-if="store.isDeveloper" />
    </div>
    <div class="filter-bar">
      <div class="filter-tabs" role="group" :aria-label="t('ui.filterByStatus')">
        <button
          v-for="filter in FILTERS"
          :key="filter.key"
          :class="{ active: activeFilter === filter.key }"
          :aria-pressed="activeFilter === filter.key"
          @click="activeFilter = filter.key"
        >
          {{ filter.label }}<span>{{ store.counts[filter.key] }}</span>
        </button>
      </div>
      <div class="section-filter">
        <AppIcon name="grid" :size="16" /><label class="sr-only" for="section-filter">{{
          t('ui.websiteSection')
        }}</label
        ><select id="section-filter" v-model="activeSection">
          <option value="">{{ t('ui.allSections') }}</option>
          <option v-for="section in store.sectionOptions" :key="section.id" :value="section.id">
            {{ section.name }}
          </option>
        </select>
      </div>
    </div>
    <p v-if="store.isDeveloper" class="task-order-help" role="status" aria-live="polite">
      {{
        t(
          store.orderPending
            ? 'management.savingOrder'
            : drag.dragging.value
              ? 'management.dragging'
              : dragEnabled
                ? 'management.dragHint'
                : 'management.filterHint',
        )
      }}
    </p>
    <div class="section-list" :aria-busy="store.orderPending">
      <SectionBlock
        v-for="group in groups"
        :key="group.id"
        :id="group.id"
        :name="group.name"
        :position="group.position"
        :tasks="group.tasks"
        :total-count="store.tasks.filter((task) => task.sectionId === group.id).length"
      />
    </div>
    <div v-if="!groups.some((group) => group.tasks.length)" class="empty-state">
      <h3>{{ store.tasks.length ? t('ui.noIdeasHereYet') : t('ui.whereShallWeStart') }}</h3>
      <p>
        {{
          store.tasks.length
            ? t('ui.tryADifferentStatusOrSection')
            : t('ui.addYourFirstIdeaForTheWebsite')
        }}
      </p>
      <button v-if="store.tasks.length" class="button secondary" @click="resetFilters">
        {{ t('ui.showAllIdeas') }}</button
      ><RouterLink v-else :to="store.newTaskPath" class="button primary">{{
        t('ui.addYourFirstIdea')
      }}</RouterLink>
    </div>
  </div>
</template>
