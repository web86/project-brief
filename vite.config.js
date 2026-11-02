import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { pdfAssets } from './deployment/pdf-assets.mjs'
import { localeBootstrap } from './deployment/locale-bootstrap.mjs'
export default defineConfig(({ mode }) => {
  const staticDemo = process.env.PROJECTBRIEF_STATIC_DEMO === '1'
  const demoBuild = process.env.PROJECTBRIEF_DEMO_BUILD === '1' || staticDemo
  const env = demoBuild ? {} : loadEnv(mode, process.cwd(), '')
  const target = env.API_PROXY_TARGET || 'http://127.0.0.1:8000'
  return {
    resolve: staticDemo
      ? {
          alias: [
            {
              find: /^.*\/services\/pdf\/index\.js$/,
              replacement: new URL('./src/services/staticDemoExport.js', import.meta.url).pathname,
            },
          ],
        }
      : undefined,
    envDir: demoBuild ? false : undefined,
    envPrefix: demoBuild ? 'PROJECTBRIEF_UNUSED_ENV_PREFIX_' : undefined,
    define: {
      'import.meta.env.VITE_STATIC_DEMO': JSON.stringify(staticDemo ? 'true' : 'false'),
      'import.meta.env.VITE_LOCAL_DEMO': JSON.stringify(demoBuild ? 'true' : 'false'),
      ...(demoBuild
        ? {
            'import.meta.env.VITE_API_BASE_URL': JSON.stringify(''),
            'import.meta.env.VITE_DATA_SOURCE': JSON.stringify('api'),
          }
        : {}),
    },
    plugins: [vue(), localeBootstrap(), ...(!staticDemo ? [pdfAssets()] : [])],
    build: {
      sourcemap: false,
      ...(demoBuild
        ? { outDir: staticDemo ? '.tools/static-demo-build' : '.tools/demo-build' }
        : {}),
    },
    worker: { format: 'es' },
    server: {
      watch: { ignored: ['**/.tools/**'] },
      proxy: { '/api': { target }, '/access/': { target }, '/client-sso': { target } },
    },
  }
})
