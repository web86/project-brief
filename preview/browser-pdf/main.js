import { createApp, nextTick } from 'vue'
import { createPinia } from 'pinia'
import App from '../../src/App.vue'
import { i18n } from '../../src/i18n/index.js'
import router from '../../src/router/index.js'
import { theme } from '../../src/services/theme.js'
import { exportRendererKey } from '../../src/services/exportRenderer.js'
import { createBrowserPdfAdapter } from './adapter.js'
import '../../src/assets/main.css'
import '../../src/assets/comment-attachments.css'

theme.initialize()
const app = createApp(App).use(createPinia()).use(i18n).use(router)
app.provide(exportRendererKey, createBrowserPdfAdapter())
router
  .isReady()
  .then(async () => {
    app.mount('#app')
    await nextTick()
    globalThis.projectBriefBootstrap?.complete()
  })
  .catch(() => globalThis.projectBriefBootstrap?.fail())
