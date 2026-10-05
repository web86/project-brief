<script setup>
import { useProjectStore } from '../stores/project'
const store = useProjectStore()
import TaskForm from '../components/TaskForm.vue'
import AppIcon from '../components/AppIcon.vue'
</script>
<template>
  <div class="form-page">
    <RouterLink :to="store.projectPath" class="back-link"
      ><AppIcon name="arrow" :size="17" />Назад к проекту</RouterLink
    >
    <div class="page-heading">
      <h1>Новая идея</h1>
      <p>Расскажите своими словами, что хотите изменить.</p>
    </div>
    <div class="form-layout">
      <div class="surface"><TaskForm /></div>
    </div>
  </div>
</template>
