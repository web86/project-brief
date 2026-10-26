import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { pdfAssets } from './deployment/pdf-assets.mjs'
import { localeBootstrap } from './deployment/locale-bootstrap.mjs'
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const target = env.API_PROXY_TARGET || 'http://127.0.0.1:8000'
  return {
    plugins: [vue(), localeBootstrap(), pdfAssets()],
    build: { sourcemap: false },
    worker: { format: 'es' },
    server: { proxy: { '/api': { target }, '/access/': { target } } },
  }
})
