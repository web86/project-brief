import { canCreateProjects } from '../services/projectPermissions.js'
import { ref } from 'vue'
import { useDiscussionsStore, useClientDiscussionsStore } from '../stores/discussions.js'
import { useTaskWorkspaceStore } from '../stores/taskWorkspace.js'
import { toast } from '../services/toast.js'
import { formatApiError } from '../i18n/index.js'
import { confirmDeveloperLeave } from '../utils/developerEditor.js'
import { t } from '../i18n/index.js'
import {
  createRouter,
  createWebHistory,
  createMemoryHistory,
  isNavigationFailure,
  NavigationFailureType,
} from 'vue-router'
import { appDestination } from '../services/session.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import { useProjectStore } from '../stores/project'
import { canEditClientTask } from '../utils/clientDraft.js'
import { api, API_MODE } from '../api/client'
import ProjectView from '../views/ProjectView.vue'

export function createProjectRouter({
  history = typeof window === 'undefined' ? createMemoryHistory() : createWebHistory(),
  apiMode = API_MODE,
  request = api.request,
} = {}) {
  const router = createRouter({
    history,
    routes: [
      {
        path: '/platform',
        component: () => import('../views/PlatformView.vue'),
        meta: { platform: true, title: 'ProjectBrief Platform' },
      },
      {
        path: '/app',
        component: () => import('../views/AppEntryView.vue'),
        meta: {
          get title() {
            return t('ui.projectAccess')
          },
        },
      },
      {
        path: '/admin/login',
        component: () => import('../views/AdminLoginView.vue'),
        meta: {
          get title() {
            return t('ui.administratorSignIn')
          },
        },
      },
      { path: '/admin/security', component: () => import('../views/AccountEnrollmentView.vue') },
      {
        path: '/admin/agreement',
        component: () => import('../views/AgreementView.vue'),
        meta: { agreement: true },
      },
      {
        path: '/w/:workspace/agreement',
        component: () => import('../views/AgreementView.vue'),
        meta: { publicDocument: true },
      },
      { path: '/invitation', component: () => import('../views/InvitationView.vue') },
      ...['forgot-password', 'reset-password'].map((path) => ({
        path: '/admin/' + path,
        component: () => import('../views/PasswordRecoveryView.vue'),
      })),
      {
        path: '/admin',
        component: () => import('../views/AdminShell.vue'),
        meta: { requiresAdmin: true, adminShell: true },
        children: [
          ...['account', 'account/security'].map((path) => ({
            path,
            component: () => import('../views/AdminAccountView.vue'),
            meta: {
              personalSettings: true,
              get title() {
                return t('account.title')
              },
            },
          })),
          {
            path: 'unavailable',
            component: () => import('../views/AdminProjectUnavailableView.vue'),
          },
          {
            path: '',
            component: () => import('../views/AdminProjectsView.vue'),
            meta: {
              get title() {
                return t('nav.allProjects')
              },
            },
          },
          {
            path: 'administrators',
            component: () => import('../views/AdminAdministratorsView.vue'),
            meta: {
              ownerOnly: true,
              get title() {
                return t('administrators.title')
              },
            },
          },
          {
            path: 'requests',
            component: () => import('../views/AdminProjectRequestsView.vue'),
            meta: {
              ownerOnly: true,
              projectRequests: true,
              get title() {
                return t('requests.title')
              },
            },
          },
          {
            path: 'settings/integrations',
            component: () => import('../views/AdminIntegrationsView.vue'),
            meta: {
              ownerOnly: true,
              get title() {
                return t('integrations.title')
              },
            },
          },
          {
            path: 'settings/notifications',
            component: () => import('../views/AdminNotificationsView.vue'),
            meta: {
              personalSettings: true,
              get title() {
                return t('centralNotifications.myNotifications')
              },
            },
          },
          {
            path: 'settings/agreement',
            component: () => import('../views/AdminAgreementSettingsView.vue'),
            meta: {
              ownerOnly: true,
              get title() {
                return t('agreement.settings')
              },
            },
          },
          {
            path: 'settings/branding',
            component: () => import('../views/AdminBrandingView.vue'),
            meta: {
              ownerOnly: true,
              get title() {
                return t('branding.title')
              },
            },
          },
          {
            path: 'projects/new',
            component: () => import('../views/AdminProjectFormView.vue'),
            meta: {
              get title() {
                return t('nav.newProject')
              },
            },
          },
          {
            path: 'projects/:uuid',
            component: () => import('../views/AdminProjectShell.vue'),
            children: [
              {
                path: '',
                redirect: (to) => ({
                  path: to.path + (to.hash === '#client-access' ? '/clients' : '/brief'),
                  hash: '',
                }),
              },
              ...[
                'forbidden',
                'discussions',
                'clients',
                'team',
                'structure',
                'notifications',
                'settings',
                'export',
              ].map((section) => ({
                path: section,
                component: {
                  forbidden: () => import('../views/ProjectPermissionDeniedView.vue'),
                  discussions: () => import('../views/AdminProjectDiscussionsView.vue'),
                  clients: () => import('../views/AdminProjectClientsView.vue'),
                  team: () => import('../views/AdminProjectTeamView.vue'),
                  structure: () => import('../views/AdminProjectStructureView.vue'),
                  notifications: () => import('../views/AdminProjectNotificationsView.vue'),
                  settings: () => import('../views/AdminProjectSettingsView.vue'),
                  export: () => import('../views/AdminProjectExportView.vue'),
                }[section],
                meta: {
                  projectSection: section,
                  get title() {
                    return t(
                      section === 'forbidden'
                        ? 'permissions.deniedTitle'
                        : section === 'team'
                          ? 'team.title'
                          : 'nav.' + section,
                    )
                  },
                },
              })),
              ...[
                ['brief', ProjectView, false],
                ['brief/task/new', () => import('../views/TaskFormView.vue'), false],
                ['brief/task/:id/edit', () => import('../views/TaskFormView.vue'), true],
                ['brief/task/:id', () => import('../views/TaskView.vue'), false],
              ].map(([path, component, editTask]) => ({
                path,
                component,
                meta: {
                  projectSection: 'brief',
                  apiProject: true,
                  admin: true,
                  editTask,
                  projectOverview: path === 'brief',
                  taskDetail: path.endsWith(':id'),
                  get title() {
                    return t('nav.brief')
                  },
                },
              })),
            ],
          },
        ],
      },
      {
        path: '/access-error',
        component: () => import('../views/AccessErrorView.vue'),
        meta: {
          get title() {
            return t('ui.projectAccess')
          },
        },
      },
      ...['discussions', 'notifications', 'export'].map((section) => ({
        path: `/project/:uuid/${section}`,
        component: {
          discussions: () => import('../views/AdminProjectDiscussionsView.vue'),
          notifications: () => import('../views/ClientNotificationsView.vue'),
          export: () => import('../views/AdminProjectExportView.vue'),
        }[section],
        meta: {
          apiProject: true,
          clientSection: section,
          get title() {
            return t('nav.' + section)
          },
        },
      })),
      {
        path: '/project/:uuid/login',
        component: () => import('../views/ClientProjectLoginView.vue'),
        meta: {
          clientLogin: true,
          get title() {
            return t('provisioning.loginTitle')
          },
        },
      },
      ...['/project/:uuid'].flatMap((path) => [
        {
          path,
          component: ProjectView,
          meta: {
            apiProject: true,
            admin: path.startsWith('/admin'),
            get title() {
              return t('ui.yourProject')
            },
          },
        },
        {
          path: `${path}/task/new`,
          component: () => import('../views/TaskFormView.vue'),
          meta: {
            apiProject: true,
            admin: path.startsWith('/admin'),
            get title() {
              return t('ui.newIdea')
            },
          },
        },
        {
          path: `${path}/task/:id/edit`,
          component: () => import('../views/TaskFormView.vue'),
          meta: {
            apiProject: true,
            admin: path.startsWith('/admin'),
            editTask: true,
            get title() {
              return t('flow.editTitle')
            },
          },
        },
        {
          path: `${path}/task/:id`,
          component: () => import('../views/TaskView.vue'),
          meta: {
            taskDetail: true,
            apiProject: true,
            admin: path.startsWith('/admin'),
            get title() {
              return t('ui.ideaDetails')
            },
          },
        },
      ]),
      {
        path: '/',
        name: 'project',
        component: ProjectView,
        meta: {
          get title() {
            return t('ui.yourProject')
          },
        },
      },
      {
        path: '/task/new',
        name: 'task-new',
        component: () => import('../views/TaskFormView.vue'),
        meta: {
          get title() {
            return t('ui.newIdea')
          },
        },
      },
      {
        path: '/task/:id/edit',
        component: () => import('../views/TaskFormView.vue'),
        meta: {
          editTask: true,
          get title() {
            return t('flow.editTitle')
          },
        },
      },
      {
        path: '/task/:id',
        name: 'task',
        component: () => import('../views/TaskView.vue'),
        meta: {
          taskDetail: true,
          get title() {
            return t('ui.ideaDetails')
          },
        },
      },
      {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('../views/NotFoundView.vue'),
        meta: {
          get title() {
            return t('ui.pageNotFound')
          },
        },
      },
    ],
    scrollBehavior(to, from, savedPosition) {
      if (to.hash === '#telegram-settings') return { el: to.hash, top: 24 }
      if (to.hash.startsWith('#comment-')) return false
      return savedPosition || { top: 0 }
    },
  })

  const navigationPending = ref(false)
  router.navigationPending = navigationPending
  let revision = 0
  const attempts = new WeakMap(),
    commits = new WeakMap()
  const reusedProject = new WeakSet()
  function canReuseProject(to, from, store) {
    return (
      to.meta.taskDetail &&
      from.meta.apiProject &&
      to.params.uuid === from.params.uuid &&
      !!to.meta.admin === !!from.meta.admin &&
      store.project.id === to.params.uuid &&
      !store.sessionLost &&
      (to.meta.admin ? !!store.admin : !!store.currentClient) &&
      !!store.findTask(to.params.id) &&
      (!to.hash.startsWith('#comment-') ||
        store
          .findTask(to.params.id)
          .comments.some((comment) => '#comment-' + comment.id === to.hash))
    )
  }
  router.beforeEach(async (to, from) => {
    const current = ++revision
    attempts.set(to, current)
    navigationPending.value = true
    const draftStore = useProjectStore()
    if (to.path !== from.path && !confirmDeveloperLeave(draftStore)) return false
    useTaskWorkspaceStore().closePanel()

    if (to.meta.platform) {
      try {
        const status = await request('/api/security/status')
        if (current !== revision) return false
        if (!status.signedIn || status.state === 'pending_2fa') return '/admin/security'
        if (status.state === 'enrollment_required') return '/admin/security'
        if (!status.platformAdmin) return '/admin/unavailable?reason=permission'
      } catch (error) {
        if (current !== revision) return false
        toast.error(() => formatApiError(error))
        return false
      }
      return true
    }
    if (apiMode && to.meta.agreement) {
      const session = await request('/api/session')
      if (['pending_2fa', 'enrollment_required'].includes(session.state)) return '/admin/security'
      if (!session.authenticated) return '/admin/login'
      if (!session.agreementRequired) return (await appDestination(request)) || '/app'
    }
    if (apiMode && to.path === '/admin/security') {
      const status = await request('/api/security/status')
      if (status.state === 'guest') return '/admin/login'
    }
    if (to.path === '/app') {
      const store = useProjectStore()
      try {
        const destination = await appDestination(request)
        if (current !== revision) return false
        if (destination) return destination
        commits.set(to, () => {
          store.apiError = ''
          store.sessionLost = false
          store.admin = null
          store.currentClient = null
          store.tasks = []
          useAdminNavigationStore().clear()
        })
        return true
      } catch (error) {
        if (current !== revision) return false
        if (from.matched.length) {
          toast.error(() => formatApiError(error))
          return false
        }
        store.apiError = error
        return true
      }
    }
    if (!apiMode) {
      if (to.meta.editTask) {
        const store = useProjectStore()
        const task = store.findTask(to.params.id)
        if (!task || (!store.isDeveloper && !canEditClientTask(task)))
          return store.taskPath(to.params.id)
      }
      return true
    }
    if (['/', '/task/new'].includes(to.path) || to.name === 'task') return '/app'
    if (to.meta.requiresAdmin) {
      const store = useProjectStore(),
        nav = useAdminNavigationStore(),
        management = useProjectManagementStore()
      try {
        if (
          !(await (to.meta.personalSettings
            ? nav.bootstrapPersonal(request)
            : nav.bootstrap(request)))
        )
          return false
        store.sessionLost = false
      } catch (error) {
        if (error.code === 'administrator_agreement_required') {
          nav.clear()
          store.admin = null
          return '/admin/agreement'
        }
        if (error.code === 'account_security_required') {
          nav.clear()
          store.admin = null
          return '/admin/security'
        }
        if ([401, 403].includes(error.status)) {
          nav.clear()
          store.admin = null
          return to.meta.personalSettings
            ? {
                path: '/admin/login',
                hash: /^#(?:verify|email)=[a-f0-9]{64}$/.test(to.hash) ? to.hash : '',
              }
            : '/admin/login'
        }
        if (from.matched.length) {
          toast.error(() => formatApiError(error))
          return false
        }
        return true
      }
      if (current !== revision) return false
      if (to.meta.ownerOnly && (!nav.workspace || nav.user?.membershipRole !== 'owner'))
        return '/admin/unavailable?reason=permission'
      if (to.path === '/admin/projects/new') {
        try {
          const me = await request('/api/admin/me')
          if (current !== revision) return false
          nav.user = me.user
          if (!canCreateProjects(me.user)) return '/admin/unavailable?reason=permission'
        } catch (error) {
          if (current !== revision) return false
          if ([401, 403].includes(error.status)) {
            nav.clear()
            return '/admin/login'
          }
          toast.error(() => formatApiError(error))
          return false
        }
      }
      if (to.params.uuid) {
        nav.pageLoading = true
        try {
          if (to.meta.apiProject) {
            if (canReuseProject(to, from, store)) {
              reusedProject.add(to)
              management.adopt(store.project)
              return true
            }
            const loaded = await store.loadApiProject(to.params.uuid, true, {
              skipAdmin: true,
              defer: true,
            })
            if (current !== revision) return false
            if (loaded === false) return false
            commits.set(to, () => {
              if (loaded() === false) return false
              management.adopt(store.project)
              nav.updateProject(store.project)
            })
          } else {
            const loaded = await management.load(to.params.uuid, to.meta.projectSection, {
              defer: true,
              isOwner: nav.user?.membershipRole === 'owner',
            })
            if (current !== revision || loaded === false) return false
            let discussionCommit
            if (to.meta.projectSection === 'discussions') {
              discussionCommit = await useDiscussionsStore().load(to.params.uuid, {
                defer: true,
                reuse: to.path === from.path,
              })
              if (current !== revision || discussionCommit === false) return false
            }
            commits.set(to, () => {
              if (loaded() === false) return false
              const { unreadCount, unreadCommentCount, ...details } = management.project
              nav.updateProject(details)
              return discussionCommit?.()
            })
          }
        } catch (error) {
          if (current !== revision) return false
          if (error.code === 'project_permission_denied')
            return `/admin/projects/${to.params.uuid}/forbidden`
          if (from.matched.length && ![401, 403, 404].includes(error.status)) {
            toast.error(() => formatApiError(error))
            return false
          }
          if ([401, 403, 404].includes(error.status)) {
            management.clear()
            store.clearApiState()
            if (error.status === 401) nav.clear()
            else nav.removeProject(to.params.uuid)
          }
          return error.status === 401 ? '/admin/login' : '/admin/unavailable'
        } finally {
          nav.pageLoading = false
        }
      }
      return true
    }
    if (to.meta.clientLogin) {
      try {
        const session = await request('/api/session')
        if (current !== revision) return false
        if (session.authenticated && session.type === 'client')
          return session.project?.id === to.params.uuid
            ? `/project/${to.params.uuid}`
            : '/access-error?reason=unavailable'
        if (session.authenticated && session.type === 'admin')
          return `/admin/projects/${to.params.uuid}/brief`
        if (['pending_2fa', 'enrollment_required'].includes(session.state)) return '/admin/security'
      } catch (error) {
        if (current !== revision) return false
        toast.error(() => formatApiError(error))
        return false
      }
    }
    if (!to.meta.apiProject) return true
    const store = useProjectStore()
    if (canReuseProject(to, from, store)) {
      reusedProject.add(to)
      return true
    }
    try {
      const loaded = await store.loadApiProject(to.params.uuid, to.meta.admin, { defer: true })
      if (current !== revision || loaded === false) return false
      let discussionCommit
      if (to.meta.clientSection === 'discussions') {
        discussionCommit = await useClientDiscussionsStore().load(to.params.uuid, {
          defer: true,
          reuse: to.path === from.path,
        })
        if (current !== revision || discussionCommit === false) return false
      }
      commits.set(to, () => {
        if (loaded() === false) return false
        return discussionCommit?.()
      })
      return true
    } catch (error) {
      if (current !== revision) return false
      if (from.matched.length && ![401, 403, 404].includes(error.status)) {
        toast.error(() => formatApiError(error))
        return false
      }
      return error.status === 401
        ? to.meta.admin
          ? '/admin/login'
          : `/project/${to.params.uuid}/login`
        : '/access-error?reason=unavailable'
    }
  })
  router.beforeResolve((to) => {
    if (attempts.get(to) !== revision) return false
    const commit = commits.get(to)
    if (commit && commit() === false) return false
    const store = useProjectStore()
    if (to.params.id && to.meta.apiProject && !store.findTask(to.params.id))
      return to.meta.admin ? '/admin/unavailable' : '/access-error?reason=unavailable'
    if (to.meta.editTask && !to.meta.admin && !canEditClientTask(store.findTask(to.params.id)))
      return store.taskPath(to.params.id)
  })
  router.onError((error, to) => {
    if (attempts.get(to) === revision) navigationPending.value = false
    toast.error(() => formatApiError(error))
  })
  router.afterEach((to, from, failure) => {
    if (isNavigationFailure(failure, NavigationFailureType.duplicated)) {
      revision++
      navigationPending.value = false
      return
    }
    if (attempts.get(to) === revision) navigationPending.value = false
    if (!failure && to.meta.apiProject && to.meta.admin && !reusedProject.has(to))
      void useAdminNavigationStore().markRead(to.params.uuid, request)
    if (!failure && to.path !== from.path) useProjectStore().discardDeveloperChanges()
  })
  return router
}
export default createProjectRouter()
