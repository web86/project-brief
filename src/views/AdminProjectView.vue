<script setup>
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import ProjectFields from '../components/ProjectFields.vue'
import ProjectSectionManager from '../components/ProjectSectionManager.vue'
import ProjectClientManager from '../components/ProjectClientManager.vue'
const route = useRoute()
const store = useProjectManagementStore()
const form = ref({})
const message = ref('')
const error = ref('')
const errors = ref({})
const pending = ref(false)
watch(
  () => route.params.uuid,
  async (id) => {
    error.value = ''
    message.value = ''
    try {
      await store.load(id)
      if (store.project?.id !== id) return
      form.value = {
        title: store.project.name,
        website: store.project.website,
        currency: store.project.currency,
        status: store.project.status,
      }
    } catch (cause) {
      error.value = cause.message
    }
  },
  { immediate: true },
)
async function save() {
  if (pending.value) return
  pending.value = true
  error.value = ''
  errors.value = {}
  try {
    await store.saveProject(form.value)
    message.value = 'Настройки сохранены.'
  } catch (cause) {
    error.value = cause.message
    errors.value = cause.errors || {}
  } finally {
    pending.value = false
  }
}
</script>
<template>
  <div>
    <RouterLink to="/admin" class="back-link">← Все проекты</RouterLink>
    <p v-if="store.loading" role="status">Открываем проект…</p>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <template v-if="store.project">
      <div class="admin-page-heading">
        <div>
          <h1>{{ store.project.name }}</h1>
          <p class="muted">
            {{ store.project.doneCount }} из {{ store.project.tasksCount }} идей готово
          </p>
        </div>
        <RouterLink :to="`/admin/projects/${store.project.id}/brief`" class="button primary"
          >Открыть идеи</RouterLink
        >
      </div>
      <div class="admin-settings-layout" :key="route.params.uuid">
        <div class="management-column"><ProjectClientManager /><ProjectSectionManager /></div>
        <form class="surface admin-form" @submit.prevent="save">
          <h2>Настройки проекта</h2>
          <ProjectFields v-model="form" :errors="errors" />
          <div class="field">
            <label for="project-status">Доступность проекта</label
            ><select id="project-status" v-model="form.status">
              <option value="active">Активен</option>
              <option value="inactive">Неактивен</option>
            </select>
          </div>
          <button class="button primary" :disabled="pending">Сохранить настройки</button>
          <p v-if="message" class="admin-feedback" role="status">{{ message }}</p>
        </form>
      </div>
    </template>
  </div>
</template>
