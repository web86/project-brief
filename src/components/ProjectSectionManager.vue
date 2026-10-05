<script setup>
import { ref } from 'vue'
import { pluralize } from '../constants/project.js'
import { useProjectManagementStore } from '../stores/projectManagement.js'
const store = useProjectManagementStore()
const newName = ref('')
const editing = ref('')
const name = ref('')
const error = ref('')
const pending = ref(false)
async function action(operation) {
  if (pending.value) return
  pending.value = true
  error.value = ''
  try {
    await operation()
  } catch (cause) {
    error.value =
      Object.values(cause.errors || {})
        .flat()
        .join(' ') || cause.message
  } finally {
    pending.value = false
  }
}
function add() {
  action(async () => {
    await store.mutateSection(null, 'POST', { name: newName.value })
    newName.value = ''
  })
}
function startRename(section) {
  editing.value = section.id
  name.value = section.name
}
function rename(section) {
  action(async () => {
    await store.mutateSection(section.id, 'PATCH', { name: name.value })
    editing.value = ''
  })
}
</script>
<template>
  <section class="surface management-panel">
    <h2>Разделы проекта</h2>
    <p class="muted">Порядок разделов определяет номера в ТЗ.</p>
    <ol class="managed-list">
      <li v-for="(section, index) in store.sections" :key="section.id" class="managed-section">
        <form v-if="editing === section.id" class="inline-edit" @submit.prevent="rename(section)">
          <label class="sr-only" :for="`rename-${section.id}`">Название раздела</label>
          <input :id="`rename-${section.id}`" v-model="name" required maxlength="2048" />
          <button class="button secondary" :disabled="pending">Сохранить</button>
          <button type="button" class="button text" @click="editing = ''">Отмена</button>
        </form>
        <template v-else>
          <div class="managed-section-title">
            <strong>{{ section.position }}. {{ section.name }}</strong
            ><span class="small muted"
              >{{ section.tasksCount }} {{ pluralize(section.tasksCount) }}</span
            >
          </div>
          <div class="order-actions">
            <button
              class="button secondary icon-button"
              :aria-label="`Поднять раздел ${section.name}`"
              :disabled="pending || index === 0"
              @click="action(() => store.mutateSection(section.id, 'PATCH', { direction: 'up' }))"
            >
              ↑
            </button>
            <button
              class="button secondary icon-button"
              :aria-label="`Опустить раздел ${section.name}`"
              :disabled="pending || index === store.sections.length - 1"
              @click="action(() => store.mutateSection(section.id, 'PATCH', { direction: 'down' }))"
            >
              ↓
            </button>
            <button class="button text" :disabled="pending" @click="startRename(section)">
              Редактировать
            </button>
            <button
              class="button text"
              :disabled="pending"
              @click="action(() => store.mutateSection(section.id, 'DELETE'))"
            >
              Удалить
            </button>
          </div>
        </template>
      </li>
    </ol>
    <form class="inline-edit add-section-form" @submit.prevent="add">
      <div class="field">
        <label for="new-section-name">Новый раздел</label
        ><input
          id="new-section-name"
          v-model="newName"
          required
          maxlength="2048"
          placeholder="Например: Личный кабинет"
        />
      </div>
      <button class="button secondary" :disabled="pending">+ Добавить раздел</button>
    </form>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
  </section>
</template>
