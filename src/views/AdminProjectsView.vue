<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api/client'
const projects = ref([])
const loading = ref(true)
const error = ref('')
async function load() {
  loading.value = true
  error.value = ''
  try {
    projects.value = (await api.request('/api/admin/projects')).data
  } catch (cause) {
    error.value = cause.message
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>
<template>
  <div>
    <div class="admin-page-heading">
      <div>
        <h1>Проекты</h1>
        <p class="muted">Идеи, которые становятся готовыми сайтами.</p>
      </div>
      <RouterLink to="/admin/projects/new" class="button primary">+ Новый проект</RouterLink>
    </div>
    <p v-if="loading" role="status">Загружаем проекты…</p>
    <div v-else-if="error" role="alert">
      <p class="field-error">{{ error }}</p>
      <button class="button secondary" @click="load">Повторить</button>
    </div>
    <div v-else-if="!projects.length" class="empty-state">
      <h2>Начните с первого проекта</h2>
      <p>Создайте проект и отправьте клиенту личную ссылку.</p>
    </div>
    <div v-else class="admin-project-list">
      <article v-for="project in projects" :key="project.id" class="surface admin-project-card">
        <div>
          <h2>{{ project.name }}</h2>
          <p class="muted">{{ project.website || 'Адрес сайта не указан' }}</p>
          <p>{{ project.clientName || 'Клиент пока не указан' }}</p>
          <p class="small muted">
            {{ project.doneCount }} из {{ project.tasksCount }} идей готово ·
            {{ project.status === 'active' ? 'Активен' : 'Неактивен' }}
          </p>
        </div>
        <div class="admin-project-actions">
          <RouterLink :to="`/admin/projects/${project.id}/brief`" class="button primary"
            >Открыть</RouterLink
          ><RouterLink :to="`/admin/projects/${project.id}#client-access`" class="button secondary"
            >Ссылка клиента</RouterLink
          ><RouterLink :to="`/admin/projects/${project.id}`" class="text-button"
            >Настройки</RouterLink
          >
        </div>
      </article>
    </div>
  </div>
</template>
