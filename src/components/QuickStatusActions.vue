<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useProjectStore } from '../stores/project'
import { applyQuickStatusAction, getQuickStatusActions } from '../utils/taskWorkflow'
import AppIcon from './AppIcon.vue'

const props = defineProps({ task: { type: Object, required: true } })
const emit = defineEmits(['clarify'])
const store = useProjectStore()
const actions = computed(() => getQuickStatusActions(props.task))
const message = ref('')
const buttons = ref(null)
watch(
  () => [props.task.id, props.task.status, props.task.clientApproved],
  () => {
    message.value = ''
  },
  { flush: 'sync' },
)

async function apply(actionId, event) {
  const result = applyQuickStatusAction(store, props.task.id, actionId)
  if (!result) return
  message.value = result.message
  if (result.focusComment) {
    emit('clarify')
    return
  }
  await nextTick()
  // Keep keyboard users in the workflow when their action button disappears.
  if (event.detail === 0 && !buttons.value?.contains(document.activeElement))
    buttons.value?.querySelector('button:not(:disabled)')?.focus()
}
</script>

<template>
  <div class="quick-status-actions">
    <p v-if="task.status === 'done'" class="developer-completed">
      <AppIcon name="check" :size="18" />Задача завершена
    </p>
    <div ref="buttons" class="quick-action-buttons" role="group" aria-label="Быстрые действия">
      <button
        v-for="action in actions"
        :key="action.id"
        type="button"
        class="button"
        :class="action.style"
        :disabled="store.storageBlocked"
        @click="apply(action.id, $event)"
      >
        <AppIcon :name="action.icon" :size="17" />{{ action.label }}
      </button>
    </div>
    <p v-if="message" class="quick-action-feedback" role="status">{{ message }}</p>
  </div>
</template>
