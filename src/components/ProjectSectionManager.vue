<script setup>
import LoadingButton from './LoadingButton.vue'
import { t, formatApiError } from '../i18n/index.js'

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
    error.value = cause.errors?.section ? { messageKey: 'flow.moveBeforeDelete' } : cause
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
    <h2>{{ t('ui.projectSections') }}</h2>
    <p class="muted">{{ t('ui.sectionOrderDeterminesNumberingInTheBrief') }}</p>
    <ol class="managed-list">
      <li v-for="(section, index) in store.sections" :key="section.id" class="managed-section">
        <form v-if="editing === section.id" class="inline-edit" @submit.prevent="rename(section)">
          <label class="sr-only" :for="`rename-${section.id}`">{{ t('ui.sectionName') }}</label>
          <input
            @invalid="$event.target.setCustomValidity(t('flow.fieldError'))"
            @input="$event.target.setCustomValidity('')"
            :id="`rename-${section.id}`"
            v-model="name"
            required
            maxlength="2048"
          />
          <LoadingButton
            type="submit"
            :busy="pending"
            class="button secondary"
            :disabled="pending"
            >{{ t('ui.save') }}</LoadingButton
          >
          <button type="button" class="button text" @click="editing = ''">
            {{ t('ui.cancel') }}
          </button>
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
              :aria-label="t('ui.moveSectionUp', { arg0: section.name })"
              :disabled="pending || index === 0"
              @click="action(() => store.mutateSection(section.id, 'PATCH', { direction: 'up' }))"
            >
              ↑
            </button>
            <button
              class="button secondary icon-button"
              :aria-label="t('ui.moveSectionDown', { arg0: section.name })"
              :disabled="pending || index === store.sections.length - 1"
              @click="action(() => store.mutateSection(section.id, 'PATCH', { direction: 'down' }))"
            >
              ↓
            </button>
            <button class="button text" :disabled="pending" @click="startRename(section)">
              {{ t('ui.edit') }}
            </button>
            <button
              class="button text"
              :disabled="pending"
              @click="action(() => store.mutateSection(section.id, 'DELETE'))"
            >
              {{ t('ui.delete') }}
            </button>
          </div>
        </template>
      </li>
    </ol>
    <form class="inline-edit add-section-form" @submit.prevent="add">
      <div class="field">
        <label for="new-section-name">{{ t('ui.newSection') }}</label
        ><input
          @invalid="$event.target.setCustomValidity(t('flow.fieldError'))"
          @input="$event.target.setCustomValidity('')"
          id="new-section-name"
          v-model="newName"
          required
          maxlength="2048"
          :placeholder="t('ui.forExampleAccountPage')"
        />
      </div>
      <LoadingButton type="submit" :busy="pending" class="button secondary" :disabled="pending">{{
        t('ui.addSection')
      }}</LoadingButton>
    </form>
    <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
  </section>
</template>
