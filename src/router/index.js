import { createRouter, createWebHistory } from 'vue-router'
import { useProjectStore } from '../stores/project'
import { api, API_MODE } from '../api/client'
import ProjectView from '../views/ProjectView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/admin/login',
      component: () => import('../views/AdminLoginView.vue'),
      meta: { title: 'Вход администратора' },
    },
    {
      path: '/admin',
      component: () => import('../views/AdminProjectsView.vue'),
      meta: { requiresAdmin: true, title: 'Проекты' },
    },
    {
      path: '/admin/projects/new',
      component: () => import('../views/AdminProjectFormView.vue'),
      meta: { requiresAdmin: true, title: 'Новый проект' },
    },
    {
      path: '/admin/projects/:uuid',
      component: () => import('../views/AdminProjectView.vue'),
      meta: { requiresAdmin: true, title: 'Настройки проекта' },
    },
    {
      path: '/access-error',
      component: () => import('../views/AccessErrorView.vue'),
      meta: { title: 'Доступ к проекту' },
    },
    ...['/project/:uuid', '/admin/projects/:uuid/brief'].flatMap((path) => [
      {
        path,
        component: ProjectView,
        meta: { apiProject: true, admin: path.startsWith('/admin'), title: 'Ваш проект' },
      },
      {
        path: `${path}/task/new`,
        component: () => import('../views/TaskFormView.vue'),
        meta: { apiProject: true, admin: path.startsWith('/admin'), title: 'Новая идея' },
      },
      {
        path: `${path}/task/:id`,
        component: () => import('../views/TaskView.vue'),
        meta: { apiProject: true, admin: path.startsWith('/admin'), title: 'Детали идеи' },
      },
    ]),
    { path: '/', name: 'project', component: ProjectView, meta: { title: 'Ваш проект' } },
    {
      path: '/task/new',
      name: 'task-new',
      component: () => import('../views/TaskFormView.vue'),
      meta: { title: 'Новая идея' },
    },
    {
      path: '/task/:id',
      name: 'task',
      component: () => import('../views/TaskView.vue'),
      meta: { title: 'Детали идеи' },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('../views/NotFoundView.vue'),
      meta: { title: 'Страница не найдена' },
    },
  ],
  scrollBehavior(to, from, savedPosition) {
    return savedPosition || { top: 0 }
  },
})

router.beforeEach(async (to) => {
  if (!API_MODE) return true
  if (['/', '/task/new'].includes(to.path) || to.name === 'task') return '/admin/login'
  if (to.meta.requiresAdmin) {
    const store = useProjectStore()
    try {
      store.admin = (await api.request('/api/admin/me')).user
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
    return true
  } catch (error) {
    return to.meta.admin && error.status === 401
      ? '/admin/login'
      : `/access-error?reason=${error.status === 401 ? 'session' : 'unavailable'}`
  }
})
export default router
