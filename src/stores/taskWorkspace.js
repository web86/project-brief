import { defineStore } from 'pinia'
import { ref } from 'vue'

const preferenceKey = 'projectbrief.task-navigation.hidden.v1'
export const useTaskWorkspaceStore = defineStore('taskWorkspace', () => {
  const hidden = ref(false),
    mobile = ref(false),
    panel = ref(null),
    expanded = ref({})
  try {
    hidden.value = globalThis.localStorage?.getItem(preferenceKey) === 'true'
  } catch {}
  function setHidden(value) {
    hidden.value = value
    try {
      globalThis.localStorage?.setItem(preferenceKey, String(value))
    } catch {}
  }
  function openTasks() {
    if (mobile.value) panel.value = 'tasks'
    else setHidden(false)
  }
  function hideTasks() {
    if (mobile.value) closePanel()
    else setHidden(true)
  }
  function openAdmin() {
    panel.value = 'admin'
  }
  function closePanel() {
    panel.value = null
  }
  function revealSection(projectId, sectionId) {
    if (!projectId || !sectionId) return
    expanded.value[projectId] ||= {}
    expanded.value[projectId][sectionId] = true
  }
  function toggleSection(projectId, sectionId) {
    expanded.value[projectId] ||= {}
    expanded.value[projectId][sectionId] = !expanded.value[projectId][sectionId]
  }
  function clear() {
    closePanel()
    expanded.value = {}
  }
  return {
    hidden,
    mobile,
    panel,
    expanded,
    setHidden,
    openTasks,
    hideTasks,
    openAdmin,
    closePanel,
    revealSection,
    toggleSection,
    clear,
  }
})
