import { createApp, nextTick } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { i18n } from './i18n/index.js'
import router from './router'
import './assets/main.css'

async function start() {
  const app = createApp(App).use(createPinia()).use(i18n).use(router)
  await router.isReady()
  app.mount('#app')
  await nextTick()
  globalThis.projectBriefBootstrap?.complete()
}
start().catch(() => globalThis.projectBriefBootstrap?.fail())
