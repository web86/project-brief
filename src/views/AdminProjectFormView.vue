<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api/client'
import ProjectFields from '../components/ProjectFields.vue'
const router = useRouter()
const data = ref({ title: '', website: '', clientName: '', clientEmail: '', currency: 'RUB' })
const errors = ref({})
const error = ref('')
const pending = ref(false)
async function create() {
  if (pending.value) return
  pending.value = true
  errors.value = {}
  error.value = ''
  try {
    const result = await api.request('/api/admin/projects', { method: 'POST', body: data.value })
    await router.push(`/admin/projects/${result.data.id}`)
  } catch (cause) {
    errors.value = cause.errors || {}
    error.value = cause.message
  } finally {
    pending.value = false
  }
}
</script>
<template>
  <div class="form-page">
    <RouterLink to="/admin" class="back-link">← Все проекты</RouterLink>
    <div class="page-heading">
      <h1>Новый проект</h1>
      <p>Дайте проекту имя — клиенту будет проще ориентироваться.</p>
    </div>
    <form class="surface admin-form" @submit.prevent="create">
      <ProjectFields v-model="data" :errors="errors" />
      <p v-if="error" class="field-error" role="alert">{{ error }}</p>
      <div class="form-actions">
        <RouterLink to="/admin" class="button secondary">Отмена</RouterLink
        ><button class="button primary" :disabled="pending">
          {{ pending ? 'Создаём…' : 'Создать проект' }}
        </button>
      </div>
    </form>
  </div>
</template>
