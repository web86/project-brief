import { confirmDeveloperLeave } from '../utils/developerEditor.js'
import { t } from '../i18n/index.js'
import { createRouter, createWebHistory, createMemoryHistory } from 'vue-router'
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
      {
        path: '/admin',
        component: () => import('../views/AdminShell.vue'),
        meta: { requiresAdmin: true, adminShell: true },
        children: [
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
                  path: to.path + (to.hash === '#client-access' ? '/clients' : '/settings'),
                  hash: '',
                }),
              },
              ...['clients', 'structure', 'notifications', 'settings'].map((section) => ({
                path: section,
                component: {
                  clients: () => import('../views/AdminProjectClientsView.vue'),
                  structure: () => import('../views/AdminProjectStructureView.vue'),
                  notifications: () => import('../views/AdminProjectNotificationsView.vue'),
                  settings: () => import('../views/AdminProjectSettingsView.vue'),
                }[section],
                meta: {
                  projectSection: section,
                  get title() {
                    return t('nav.' + section)
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
      return savedPosition || { top: 0 }
    },
  })

  router.beforeEach(async (to, from) => {
    const draftStore = useProjectStore()
    if (to.path !== from.path && !confirmDeveloperLeave(draftStore)) return false

    if (to.path === '/app') {
      const store = useProjectStore()
      store.apiError = ''
      store.sessionLost = false
      store.admin = null
      store.currentClient = null
      store.tasks = []
      useAdminNavigationStore().clear()
      try {
        return (await appDestination(request)) || true
      } catch (error) {
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
        if (!(await nav.bootstrap(request))) return false
        store.sessionLost = false
      } catch (error) {
        if ([401, 403].includes(error.status)) {
          nav.clear()
          store.admin = null
          return '/admin/login'
        }
        return true
      }
      if (to.params.uuid) {
        nav.pageLoading = true
        try {
          if (to.meta.apiProject) {
            const loaded = await store.loadApiProject(to.params.uuid, true, { skipAdmin: true })
            if (loaded === false) return false
            management.adopt(store.project)
            if (to.params.id && !store.findTask(to.params.id)) return '/admin/unavailable'
            await nav.markRead(to.params.uuid, request)
          } else {
            await management.load(to.params.uuid, to.meta.projectSection)
          }
        } catch (error) {
          return error.status === 401 ? '/admin/login' : '/admin/unavailable'
        } finally {
          nav.pageLoading = false
        }
      } else if (to.path === '/admin' && from.meta.adminShell && !nav.switching) {
        await nav.refreshProjects(request)
      }
      return true
    }
    if (!to.meta.apiProject) return true
    const store = useProjectStore()
    try {
      await store.loadApiProject(to.params.uuid, to.meta.admin)
      if (to.meta.editTask && !to.meta.admin && !canEditClientTask(store.findTask(to.params.id)))
        return store.taskPath(to.params.id)
      return true
    } catch (error) {
      return to.meta.admin && error.status === 401
        ? '/admin/login'
        : `/access-error?reason=${error.status === 401 ? 'session' : 'unavailable'}`
    }
  })
  router.afterEach((to, from, failure) => {
    if (!failure && to.path !== from.path) useProjectStore().discardDeveloperChanges()
  })
  return router
}
export default createProjectRouter()
