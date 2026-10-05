<script setup>
import TaskCard from './TaskCard.vue'
import AppIcon from './AppIcon.vue'
import { pluralize } from '../constants/project'
defineProps({ name: String, tasks: Array })
const icons = {
  Главная: 'home',
  Каталог: 'bag',
  'Карточка товара': 'box',
  Контакты: 'pin',
  Общее: 'grid',
  Другое: 'grid',
}
</script>
<template>
  <section class="section-block" :aria-label="name">
    <div class="section-heading">
      <span class="section-icon"><AppIcon :name="icons[name] || 'grid'" :size="18" /></span>
      <h2 :title="name">{{ name }}</h2>
      <span class="section-count">{{ tasks.length }} {{ pluralize(tasks.length) }}</span>
    </div>
    <div class="task-grid"><TaskCard v-for="task in tasks" :key="task.id" :task="task" /></div>
  </section>
</template>
