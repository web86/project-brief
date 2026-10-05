<script setup>
import { ref } from 'vue'
import { useProjectStore } from '../stores/project'
import { STATUSES } from '../constants/project'
import AppIcon from './AppIcon.vue'
const props = defineProps({ task: Object })
const store = useProjectStore()
const errors = ref({})
function updateNumber(key, event) {
  const value = event.target.value === '' ? null : Number(event.target.value)
  if (event.target.validity.badInput || (value !== null && (!Number.isFinite(value) || value < 0))) {
    errors.value[key] = 'Введите число не меньше нуля.'
    return
  }
  errors.value[key] = ''
  store.updateDeveloperData(props.task.id, { [key]: value })
}
</script>
<template>
  <section class="surface developer-panel"><div class="developer-heading"><AppIcon name="code" :size="19" /><h2>Для разработчика</h2></div><p class="panel-help">Внутренняя информация. Изменения сохраняются автоматически.</p><div class="field"><label for="developer-status">Статус идеи</label><select id="developer-status" :value="task.status" :disabled="store.storageBlocked" @change="store.changeStatus(task.id, $event.target.value)"><option v-for="(status, key) in STATUSES" :key="key" :value="key">{{ status.label }}</option></select></div><div class="developer-numbers"><div class="field"><label for="estimate">Оценка, часов</label><input id="estimate" type="number" min="0" step="any" inputmode="decimal" placeholder="Не указана" :value="task.estimateHours ?? ''" :disabled="store.storageBlocked" :aria-invalid="!!errors.estimateHours" aria-describedby="estimate-error" @input="updateNumber('estimateHours', $event)" /><p v-if="errors.estimateHours" id="estimate-error" class="field-error">{{ errors.estimateHours }}</p></div><div class="field"><label for="price">Стоимость, ₽</label><input id="price" type="number" min="0" step="any" inputmode="decimal" placeholder="Не указана" :value="task.price ?? ''" :disabled="store.storageBlocked" :aria-invalid="!!errors.price" aria-describedby="price-error" @input="updateNumber('price', $event)" /><p v-if="errors.price" id="price-error" class="field-error">{{ errors.price }}</p></div></div><div class="field"><label for="developer-notes">Технические заметки</label><textarea id="developer-notes" :value="task.developerNotes" :disabled="store.storageBlocked" rows="5" maxlength="10000" placeholder="Компоненты, детали реализации, что стоит учесть…" @input="store.updateDeveloperData(task.id, { developerNotes: $event.target.value })" /></div><p class="developer-approval"><AppIcon :name="task.clientApproved ? 'check' : 'clock'" :size="16" />{{ task.clientApproved ? 'Клиент согласовал задачу' : 'Клиент ещё не согласовал задачу' }}</p></section>
</template>
