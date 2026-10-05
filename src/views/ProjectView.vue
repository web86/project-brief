<script setup>
import { computed, ref } from 'vue'
import { useProjectStore } from '../stores/project'
import { FILTERS } from '../constants/project'
import ProjectHeader from '../components/ProjectHeader.vue'
import ProjectStats from '../components/ProjectStats.vue'
import SectionBlock from '../components/SectionBlock.vue'
import AppIcon from '../components/AppIcon.vue'

const store = useProjectStore()
const activeFilter = ref('all')
const activeSection = ref('all')
function resetFilters() {
  activeFilter.value = 'all'
  activeSection.value = 'all'
}
const filteredTasks = computed(() =>
  store.tasks.filter(
    (task) =>
      FILTERS.find((filter) => filter.key === activeFilter.value).statuses.includes(task.status) &&
      (activeSection.value === 'all' || task.section === activeSection.value),
  ),
)
const groups = computed(() =>
  store.sectionOptions
    .map((name) => ({ name, tasks: filteredTasks.value.filter((task) => task.section === name) }))
    .filter((group) => group.tasks.length),
)
</script>
<template>
  <div class="dashboard">
    <ProjectHeader /><ProjectStats
      :counts="store.counts"
      :active-filter="activeFilter"
      @select="activeFilter = $event"
    />
    <aside class="welcome-note">
      <span class="welcome-icon"><AppIcon name="leaf" :size="24" /></span>
      <div>
        <strong>Большие перемены начинаются с маленькой идеи</strong>
        <p>Расскажите, что хочется улучшить. Мы обсудим детали и вместе найдём решение.</p>
      </div>
      <RouterLink to="/task/new" class="note-link"
        >Поделиться идеей <AppIcon name="chevron" :size="15"
      /></RouterLink>
    </aside>
    <div class="ideas-heading">
      <div>
        <h2>
          Идеи и изменения <span class="count-pill">{{ store.tasks.length }}</span>
        </h2>
        <p>Всё, что мы хотим сделать для вашего сайта</p>
      </div>
      <span class="local-indicator"
        ><span class="tiny-dot" />{{
          store.storageError ? 'Есть несохранённые изменения' : 'Сохранено в браузере'
        }}</span
      >
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
          <option value="all">Все разделы</option>
          <option v-for="section in store.sectionOptions" :key="section" :value="section">
            {{ section }}
          </option>
        </select>
      </div>
    </div>
    <div class="section-list">
      <SectionBlock
        v-for="group in groups"
        :key="group.name"
        :name="group.name"
        :tasks="group.tasks"
      />
    </div>
    <div v-if="!groups.length" class="empty-state">
      <span class="empty-icon"><AppIcon name="leaf" :size="30" /></span>
      <h3>{{ store.tasks.length ? 'Здесь пока нет идей' : 'С чего начнём?' }}</h3>
      <p>
        {{
          store.tasks.length
            ? 'Попробуйте выбрать другой статус или раздел.'
            : 'Расскажите, что хочется изменить на сайте. Не нужно подбирать технические слова.'
        }}
      </p>
      <button v-if="store.tasks.length" class="button secondary" @click="resetFilters">
        Показать все идеи</button
      ><RouterLink v-else to="/task/new" class="button primary">Добавить первую идею</RouterLink>
    </div>
    <footer class="project-footer">
      <AppIcon name="leaf" :size="15" /><span>Хороший сайт начинается с диалога.</span>
    </footer>
  </div>
</template>
