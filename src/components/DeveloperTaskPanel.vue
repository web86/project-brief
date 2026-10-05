<script setup>
import { computed, ref } from 'vue'
import { useProjectStore } from '../stores/project'
import { STATUSES } from '../constants/project'
import AppIcon from './AppIcon.vue'
import QuickStatusActions from './QuickStatusActions.vue'
import { getProjectCurrencySymbol } from '../utils/currency'
const props = defineProps({ task: Object })
const emit = defineEmits(['clarify'])
const store = useProjectStore()
const currencySymbol = computed(() => getProjectCurrencySymbol(store.project))
const errors = ref({})
const structurePending = ref(false)
const positionIndex = computed(() =>
  store.sectionTasks(props.task.sectionId).findIndex((task) => task.id === props.task.id),
)
async function structureAction(operation) {
  structurePending.value = true
  try {
    await operation()
  } catch (cause) {
    errors.value.structure = cause.message
  } finally {
    structurePending.value = false
  }
}
function updateNumber(key, event) {
  const value = event.target.value === '' ? null : Number(event.target.value)
  if (
    event.target.validity.badInput ||
    (value !== null && (!Number.isFinite(value) || value < 0))
  ) {
    errors.value[key] = 'Введите число не меньше нуля.'
    return
  }
  errors.value[key] = ''
  store.updateDeveloperData(props.task.id, { [key]: value })
}
</script>
<template>
  <section class="surface developer-panel">
    <div class="developer-heading">
      <AppIcon name="code" :size="19" />
      <h2>Управление задачей</h2>
    </div>
    <div class="field">
      <label for="developer-status">Статус идеи</label
      ><select
        id="developer-status"
        :value="task.status"
        :disabled="store.storageBlocked"
        @change="store.changeStatus(task.id, $event.target.value)"
      >
        <option v-for="(status, key) in STATUSES" :key="key" :value="key">
          {{ status.label }}
        </option>
      </select>
    </div>
    <div class="field">
      <label for="task-brief-section">Раздел ТЗ</label>
      <select
        id="task-brief-section"
        :value="task.sectionId"
        :disabled="structurePending || store.storageBlocked"
        @change="structureAction(() => store.moveTask(task.id, $event.target.value))"
      >
        <option v-for="section in store.sectionOptions" :key="section.id" :value="section.id">
          {{ section.position }}. {{ section.name }}
        </option>
      </select>
      <div class="order-actions">
        <button
          class="button secondary"
          aria-label="Переместить задачу выше"
          :disabled="structurePending || store.storageBlocked || positionIndex <= 0"
          @click="structureAction(() => store.reorderTask(task.id, 'up'))"
        >
          ↑ Выше
        </button>
        <button
          class="button secondary"
          aria-label="Переместить задачу ниже"
          :disabled="
            structurePending ||
            store.storageBlocked ||
            positionIndex >= store.sectionTasks(task.sectionId).length - 1
          "
          @click="structureAction(() => store.reorderTask(task.id, 'down'))"
        >
          ↓ Ниже
        </button>
      </div>
      <p v-if="errors.structure" class="field-error" role="alert">{{ errors.structure }}</p>
    </div>
    <QuickStatusActions :task="task" @clarify="emit('clarify')" />
    <div class="developer-numbers">
      <div class="field">
        <label for="estimate">Оценка</label>
        <div class="number-with-unit">
          <input
            id="estimate"
            type="number"
            min="0"
            step="any"
            inputmode="decimal"
            placeholder="—"
            :value="task.estimateHours ?? ''"
            :disabled="store.storageBlocked"
            :aria-invalid="!!errors.estimateHours"
            aria-label="Оценка, часов"
            :aria-describedby="errors.estimateHours ? 'estimate-error' : undefined"
            @change="store.apiMode && store.flushDeveloperData()"
            @input="updateNumber('estimateHours', $event)"
          /><span aria-hidden="true">ч</span>
        </div>
        <p v-if="errors.estimateHours" id="estimate-error" class="field-error">
          {{ errors.estimateHours }}
        </p>
      </div>
      <div class="field">
        <label for="price">Стоимость</label>
        <div class="number-with-unit">
          <input
            id="price"
            type="number"
            min="0"
            step="any"
            inputmode="decimal"
            placeholder="—"
            :value="task.price ?? ''"
            :disabled="store.storageBlocked"
            :aria-invalid="!!errors.price"
            :aria-label="`Стоимость, ${currencySymbol}`"
            :aria-describedby="errors.price ? 'price-error' : undefined"
            @change="store.apiMode && store.flushDeveloperData()"
            @input="updateNumber('price', $event)"
          /><span aria-hidden="true">{{ currencySymbol }}</span>
        </div>
        <p v-if="errors.price" id="price-error" class="field-error">{{ errors.price }}</p>
      </div>
    </div>
    <p class="developer-approval" :class="{ approved: task.clientApproved }">
      <AppIcon :name="task.clientApproved ? 'check' : 'clock'" :size="16" />{{
        task.clientApproved ? 'ТЗ согласовано клиентом' : 'Ожидает согласования клиента'
      }}
    </p>
  </section>
</template>
