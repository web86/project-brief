import { useTaskWorkspaceStore } from './taskWorkspace.js'
import { useDiscussionsStore } from './discussions.js'
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../api/client.js'
import { useProjectStore } from './project.js'
import { useProjectManagementStore } from './projectManagement.js'
import { confirmDeveloperLeave } from '../utils/developerEditor.js'
import { toast } from '../services/toast.js'
import { t, formatApiError, applyClientLocale } from '../i18n/index.js'

export const useAdminNavigationStore = defineStore('adminNavigation', () => {
  const user = ref(null),
    workspace = ref(null),
    workspaces = ref([]),
    projects = ref([])
  const loading = ref(false),
    refreshing = ref(false),
    switching = ref(false)
  const pageLoading = ref(false)
  const error = ref(null),
    listError = ref(null),
    ready = ref(false)
  let revision = 0,
    listRevision = 0,
    pending = null
  function clearProjectState() {
    useProjectStore().clearApiState()
    useProjectManagementStore().clear()
    useDiscussionsStore().clear()
    useTaskWorkspaceStore().clear()
    projects.value = []
    listRevision++
  }
  function clear() {
    revision++
    pending = null
    clearProjectState()
    user.value = null
    workspace.value = null
    workspaces.value = []
    ready.value = false
    loading.value = false
    error.value = null
    listError.value = null
  }
  async function bootstrap(request = api.request, force = false) {
    if (ready.value && !force) return true
    if (pending && !force) return pending
    const current = ++revision
    loading.value = true
    error.value = null
    pending = (async () => {
      try {
        const [me, choices, list] = await Promise.all([
          request('/api/admin/me'),
          request('/api/admin/workspaces'),
          request('/api/admin/projects'),
        ])
        if (current !== revision) return false
        user.value = me.user
        workspace.value = me.workspace
        workspaces.value = choices.data
        projects.value = list.data
        useProjectStore().admin = me.user
        applyClientLocale(me.user.preferredLocale)
        ready.value = true
        return true
      } catch (cause) {
        if (current === revision) error.value = cause
        throw cause
      } finally {
        if (current === revision) {
          loading.value = false
          pending = null
        }
      }
    })()
    return pending
  }
  async function refreshProjects(request = api.request) {
    const current = revision,
      listCurrent = ++listRevision
    refreshing.value = true
    listError.value = null
    try {
      const list = await request('/api/admin/projects')
      if (current === revision && listCurrent === listRevision) projects.value = list.data
    } catch (cause) {
      if (current === revision) {
        listError.value = cause
        toast.error(() => formatApiError(cause))
      }
    } finally {
      if (current === revision && listCurrent === listRevision) refreshing.value = false
    }
  }
  async function switchWorkspace(id, router, confirm) {
    if (switching.value || id === workspace.value?.id) return false
    if (!confirmDeveloperLeave(useProjectStore(), confirm)) return false
    switching.value = true
    clear()
    try {
      await api.request(`/api/admin/workspaces/${id}/select`, { method: 'POST' })
      await bootstrap(api.request, true)
      await router.replace('/admin')
      toast.success(() => t('nav.workspaceChanged'))
      return true
    } catch (cause) {
      toast.error(() => formatApiError(cause))
      // The selection may have reached the server despite a lost response. Resolve its actual state.
      try {
        await bootstrap(api.request, true)
      } catch {
        /* Inline retry remains available. */
      }
      await router.replace('/admin')
      return false
    } finally {
      switching.value = false
    }
  }
  async function markRead(id, request = api.request) {
    const current = revision
    try {
      const result = await request(`/api/admin/projects/${id}/activity/read`, { method: 'POST' })
      if (current === revision) {
        // Older list snapshots cannot resurrect a badge after this successful read.
        listRevision++
        refreshing.value = false
        const project = projects.value.find((project) => project.id === id)
        if (project) project.unreadCount = result.unreadCount
      }
    } catch (cause) {
      if (current === revision) toast.error(() => formatApiError(cause))
    }
  }
  function updateCommentCount(id, count) {
    listRevision++
    refreshing.value = false
    const project = projects.value.find((item) => item.id === id)
    if (project) project.unreadCommentCount = count
  }
  function updateProject(project) {
    const index = projects.value.findIndex((item) => item.id === project.id)
    if (index < 0) projects.value.unshift({ unreadCount: 0, ...project })
    else projects.value[index] = { ...projects.value[index], ...project }
  }
  return {
    user,
    workspace,
    workspaces,
    projects,
    pageLoading,
    loading,
    refreshing,
    switching,
    error,
    listError,
    ready,
    clear,
    bootstrap,
    refreshProjects,
    switchWorkspace,
    markRead,
    updateProject,
    updateCommentCount,
  }
})
