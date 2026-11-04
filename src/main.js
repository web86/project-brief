import { initializeDemo, blockDemoExternalLink } from './services/demo.js'
import { createApp, nextTick, watch } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { i18n } from './i18n/index.js'
import router from './router'
import './assets/main.css'
import './assets/comment-attachments.css'
import { stepUpRequest } from './services/stepUp.js'
import { theme } from './services/theme.js'

theme.initialize()
if (import.meta.hot) import.meta.hot.dispose(theme.dispose)

async function start() {
  await initializeDemo().catch(() => {})
  document.addEventListener('click', blockDemoExternalLink, true)
  const app = createApp(App).use(createPinia()).use(i18n).use(router)
  // A route guard can wait for MFA during the first navigation.
  app.mount('#app')
  let completed = false
  const complete = () => {
    if (completed) return
    completed = true
    globalThis.projectBriefBootstrap?.complete()
  }
  const stop = watch(
    stepUpRequest,
    (request) => {
      if (request) complete()
    },
    { immediate: true },
  )
  try {
    await router.isReady()
    await nextTick()
    complete()
  } finally {
    stop()
  }
}
start().catch(() => globalThis.projectBriefBootstrap?.fail())
