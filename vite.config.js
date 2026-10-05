import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const target = env.API_PROXY_TARGET || 'http://127.0.0.1:8000'
  return {
    plugins: [vue()],
    build: { sourcemap: false },
    server: { proxy: { '/api': { target }, '/access/': { target } } },
  }
})
