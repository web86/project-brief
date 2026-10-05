<script setup>
import { FILTERS } from '../constants/project'
defineProps({ counts: Object, activeFilter: String })
defineEmits(['select'])
const colors = { all: 'gray', ideas: 'orange', approved: 'violet', working: 'blue', done: 'green' }
</script>
<template>
  <div class="project-stats" aria-label="Статистика проекта">
    <button v-for="filter in FILTERS" :key="filter.key" class="stat-item" :class="{ selected: activeFilter === filter.key }" :aria-pressed="activeFilter === filter.key" @click="$emit('select', filter.key)"><span class="stat-label"><span class="status-dot" :class="`tone-${colors[filter.key]}`" />{{ filter.key === 'all' ? 'Всего идей' : filter.label }}</span><span class="stat-number">{{ counts[filter.key] }}</span></button>
  </div>
</template>
