import { ref, watch } from 'vue'
import { toast } from '../services/toast.js'
import { t } from '../i18n/index.js'

export const canDeleteTask = (task) => !!task && task.status === 'new' && !task.clientApproved

export const canEditClientTask = (task) =>
  !!task && !task.clientApproved && ['new', 'clarification'].includes(task.status)

export function useDraftDeletion({ store, task, router }) {
  const open = ref(false)
  const pending = ref(false)
  const canDelete = () => canDeleteTask(task.value)
  function requestDeletion() {
    if (canDelete()) open.value = true
  }
  function cancel() {
    if (!pending.value) open.value = false
  }
  async function confirm() {
    if (!open.value || pending.value || !canDelete()) return false
    pending.value = true
    try {
      if (!(await store.deleteTask(task.value.id))) return false
      open.value = false
      toast.success(() => t('management.trashed'))
      await router.push(store.projectPath)
      return true
    } finally {
      pending.value = false
    }
  }
  watch(
    () => canDeleteTask(task.value),
    (editable) => {
      if (!editable) open.value = false
    },
  )
  return { open, pending, requestDeletion, cancel, confirm }
}
