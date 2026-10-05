import { createRouter, createWebHistory } from 'vue-router'
import ProjectView from '../views/ProjectView.vue'

export default createRouter({
  history: createWebHistory(),
  routes: [
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
