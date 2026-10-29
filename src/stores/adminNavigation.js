import { useProjectRequestsStore } from './projectRequests.js'
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
  const syncCursor = ref(null)
  const pendingRequests = ref(0)
  const user = ref(null),
    workspace = ref(null),
    workspaces = ref([]),
    projects = ref([])
  const loading = ref(false),
    refreshing = ref(false),
    switching = ref(false)
  const pageLoading = ref(false)
  const projectsPath = ref('/admin')
  const error = ref(null),
    listError = ref(null),
    ready = ref(false)
  let revision = 0,
    listRevision = 0,
    pending = null
  function clearProjectState() {
    useWorkspaceBrandingStore().clear()
    useProjectRequestsStore().clear()
    pendingRequests.value = 0
    useProjectListingStore().clear()
    projectsPath.value = '/admin'
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
    syncCursor.value = null
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
        const [me, choices] = await Promise.all([
          request('/api/admin/me'),
          request('/api/admin/workspaces'),
        ])
        if (current !== revision) return false
        syncCursor.value = me.syncCursor
        pendingRequests.value = me.pendingRequests || 0
        user.value = me.user
        workspace.value = me.workspace
        workspaces.value = choices.data
        projects.value = []
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
  async function refreshRequestCount(request = api.request) {
    if (user.value?.membershipRole !== 'owner' || !workspace.value) {
      pendingRequests.value = 0
      return
    }
    const current = revision,
      id = workspace.value.id
    const result = await request('/api/admin/project-requests/count')
    if (
      current === revision &&
      workspace.value?.id === id &&
      user.value?.membershipRole === 'owner'
    )
      pendingRequests.value = result.pending
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
  function adoptBranding(id, settings) {
    if (!workspace.value || workspace.value.id !== id) return
    workspace.value = {
      ...workspace.value,
      branding: {
        companyName: settings.visual.companyName,
        primaryColor: settings.visual.primaryColor,
        logoUrl: settings.assets.logo,
        faviconUrl: settings.assets.favicon,
        faviconType:
          settings.assetInfo?.favicon?.mime ||
          (settings.assets.favicon?.endsWith('.svg') ? 'image/svg+xml' : 'image/png'),
      },
    }
  }
  function updateProject(project) {
    const index = projects.value.findIndex((item) => item.id === project.id)
    if (index < 0) projects.value.unshift({ unreadCount: 0, ...project })
    else projects.value[index] = { ...projects.value[index], ...project }
  }
  return {
    pendingRequests,
    refreshRequestCount,
    syncCursor,
    user,
    workspace,
    workspaces,
    projects,
    projectsPath,
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
    switchWorkspace,
    markRead,
    projectForRoute,
    removeProject,
    updateProject,
    adoptBranding,
    updateCommentCount,
  }
})
