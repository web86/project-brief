import { usePersonalNotificationsStore } from './personalNotifications.js'
import { useTaskWorkspaceStore } from './taskWorkspace.js'
import { useDiscussionsStore, useClientDiscussionsStore } from './discussions.js'
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../api/client.js'
import { useProjectStore } from './project.js'
import { useProjectManagementStore } from './projectManagement.js'
import { useProjectListingStore } from './projectListing.js'
import { useWorkspaceBrandingStore } from './workspaceBranding.js'
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
  const projectsPath = ref('/admin'),
    sidebarPage = ref(1),
    sidebarLastPage = ref(1)
  const error = ref(null),
    listError = ref(null),
    ready = ref(false)
  let revision = 0,
    listRevision = 0,
    pending = null
  function clearProjectState() {
    useWorkspaceBrandingStore().clear()
    useProjectListingStore().clear()
    projectsPath.value = '/admin'
    sidebarPage.value = 1
    sidebarLastPage.value = 1
    useProjectStore().clearApiState()
    useProjectManagementStore().clear()
    useDiscussionsStore().clear()
    useClientDiscussionsStore().clear()
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
    if (ready.value && workspace.value && !force) return true
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
        sidebarPage.value = list.meta?.current_page || 1
        sidebarLastPage.value = list.meta?.last_page || 1
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
  async function bootstrapPersonal(request = api.request) {
    const current = ++revision
    pending = null
    loading.value = true
    error.value = null
    try {
      const state = await request('/api/admin/notifications')
      if (current !== revision) return false
      if (user.value && user.value.email !== state.user.email) {
        clearProjectState()
        workspace.value = null
      }
      const personal = usePersonalNotificationsStore()
      personal.setIdentity('admin', state.user.notificationPreferenceId)
      personal.adoptSettings(state)
      user.value = state.user
      workspaces.value = state.workspaces || []
      if (!workspaces.value.some((item) => item.id === workspace.value?.id)) {
        clearProjectState()
        workspace.value = null
      }
      useProjectStore().admin = state.user
      applyClientLocale(state.user.preferredLocale)
      ready.value = true
      return true
    } catch (cause) {
      if (current === revision) error.value = cause
      throw cause
    } finally {
      if (current === revision) loading.value = false
    }
  }
  async function refreshProjects(request = api.request) {
    const current = revision,
      listCurrent = ++listRevision
    refreshing.value = true
    listError.value = null
    try {
      const list = await request('/api/admin/projects')
      if (current === revision && listCurrent === listRevision) {
        projects.value = list.data
        sidebarPage.value = list.meta?.current_page || 1
        sidebarLastPage.value = list.meta?.last_page || 1
      }
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
    if (!useWorkspaceBrandingStore().confirmLeave()) return false
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
  async function moreProjects() {
    if (refreshing.value || sidebarPage.value >= sidebarLastPage.value) return
    const current = revision,
      listCurrent = ++listRevision
    refreshing.value = true
    listError.value = null
    try {
      const list = await api.request('/api/admin/projects?page=' + (sidebarPage.value + 1))
      if (current !== revision || listCurrent !== listRevision) return
      const known = new Set(projects.value.map((p) => p.id))
      projects.value.push(...list.data.filter((p) => !known.has(p.id)))
      sidebarPage.value = list.meta?.current_page || sidebarPage.value + 1
      sidebarLastPage.value = list.meta?.last_page || sidebarPage.value
    } catch (cause) {
      if (current === revision && listCurrent === listRevision) listError.value = cause
    } finally {
      if (current === revision && listCurrent === listRevision) refreshing.value = false
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
  function projectForRoute(id) {
    const project = useProjectManagementStore().project
    return ready.value && !switching.value && id && project?.id === id ? project : null
  }
  function removeProject(id) {
    listRevision++
    refreshing.value = false
    projects.value = projects.value.filter((project) => project.id !== id)
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
    projectsPath,
    sidebarPage,
    sidebarLastPage,
    moreProjects,
    pageLoading,
    loading,
    refreshing,
    switching,
    error,
    listError,
    ready,
    clear,
    bootstrap,
    bootstrapPersonal,
    refreshProjects,
    switchWorkspace,
    markRead,
    projectForRoute,
    removeProject,
    updateProject,
    updateCommentCount,
  }
})
