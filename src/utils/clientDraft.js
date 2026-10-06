import { ref, watch } from 'vue'

export const canEditClientTask = (task) =>
  !!task && !task.clientApproved && ['new', 'clarification'].includes(task.status)

export function useDraftDeletion({ store, task, router }) {
  const open = ref(false)
  const pending = ref(false)
  const canDelete = () => !store.isDeveloper && canEditClientTask(task.value)
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
      await router.push(store.projectPath)
      return true
    } finally {
      pending.value = false
    }
  }
  watch(
    () => canEditClientTask(task.value),
    (editable) => {
      if (!editable) open.value = false
    },
  )
  return { open, pending, requestDeletion, cancel, confirm }
}
