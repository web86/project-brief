<script setup>
import { computed, ref } from 'vue'
import { useProjectStore } from '../stores/project'
import { FILTERS } from '../constants/project'
import { groupBriefTasks } from '../utils/structure'
import ProjectHeader from '../components/ProjectHeader.vue'
import SectionBlock from '../components/SectionBlock.vue'
import AppIcon from '../components/AppIcon.vue'

const store = useProjectStore()
const activeFilter = ref('all')
const activeSection = ref('')
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
      <h2>Идеи и изменения</h2>
    </div>
    <div class="filter-bar">
      <div class="filter-tabs" role="group" aria-label="Фильтр по состоянию">
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
        <AppIcon name="grid" :size="16" /><label class="sr-only" for="section-filter"
          >Раздел сайта</label
        ><select id="section-filter" v-model="activeSection">
          <option value="">Все разделы</option>
          <option v-for="section in store.sectionOptions" :key="section.id" :value="section.id">
            {{ section.name }}
          </option>
        </select>
      </div>
    </div>
    <div class="section-list">
      <SectionBlock
        v-for="group in groups"
        :key="group.id"
        :name="group.name"
        :position="group.position"
        :tasks="group.tasks"
      />
    </div>
    <div v-if="!groups.length" class="empty-state">
      <h3>{{ store.tasks.length ? 'Здесь пока нет идей' : 'С чего начнём?' }}</h3>
      <p>
        {{
          store.tasks.length
            ? 'Попробуйте выбрать другой статус или раздел.'
            : 'Добавьте первую идею для вашего сайта.'
        }}
      </p>
      <button v-if="store.tasks.length" class="button secondary" @click="resetFilters">
        Показать все идеи</button
      ><RouterLink v-else :to="store.newTaskPath" class="button primary"
        >Добавить первую идею</RouterLink
      >
    </div>
  </div>
</template>
