import { t } from '../i18n/index.js'
import { createRouter, createWebHistory, createMemoryHistory } from 'vue-router'
import { appDestination } from '../services/session.js'
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
        component: () => import('../views/AdminProjectsView.vue'),
        meta: {
          requiresAdmin: true,
          get title() {
            return t('ui.projects')
          },
        },
      },
      {
        path: '/admin/projects/new',
        component: () => import('../views/AdminProjectFormView.vue'),
        meta: {
          requiresAdmin: true,
          get title() {
            return t('ui.newProject2')
          },
        },
      },
      {
        path: '/admin/projects/:uuid',
        component: () => import('../views/AdminProjectView.vue'),
        meta: {
          requiresAdmin: true,
          get title() {
            return t('ui.projectSettings')
          },
        },
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
      ...['/project/:uuid', '/admin/projects/:uuid/brief'].flatMap((path) => [
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

  router.beforeEach(async (to) => {
    if (to.path === '/app') {
      const store = useProjectStore()
      if (store.admin && Object.keys(store.developerDrafts).length) await store.flushDeveloperData()
      store.apiError = ''
      store.sessionLost = false
      store.admin = null
      store.currentClient = null
      store.tasks = []
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
    const pendingStore = useProjectStore()
    if (pendingStore.admin && Object.keys(pendingStore.developerDrafts).length)
      await pendingStore.flushDeveloperData()
    if (['/', '/task/new'].includes(to.path) || to.name === 'task') return '/app'
    if (to.meta.requiresAdmin) {
      const store = useProjectStore()
      try {
        store.admin = (await request('/api/admin/me')).user
        store.sessionLost = false
        return true
      } catch {
        store.admin = null
        return '/admin/login'
      }
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
  return router
}
export default createProjectRouter()
