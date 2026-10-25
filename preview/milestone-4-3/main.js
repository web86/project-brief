import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import Preview from './Preview.vue'
import '../../src/assets/main.css'
import './public.css'

// A separate entry: no application store, API client, session or service worker.
const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', component: {} },
    { path: '/demo', component: {} },
  ],
  scrollBehavior(to) {
    return to.hash
      ? {
          el: to.hash,
          behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        }
      : { top: 0 }
  },
})
createApp(Preview).use(router).mount('#preview')
