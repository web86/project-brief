import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'
import { readFileSync, createReadStream, statSync } from 'node:fs'
const licenses = {
  'pdfmake-MIT.txt': 'node_modules/pdfmake/LICENSE',
  'NotoSansSC-OFL.txt': 'api/resources/fonts/NotoSansSC-OFL.txt',
  'DejaVu-LICENSE.txt': 'api/resources/fonts/DejaVu-LICENSE.txt',
}
const root = fileURLToPath(new URL('../..', import.meta.url)).replace(/\/$/, '')
const fontPaths = [
  'api/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf',
  'api/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf',
  'api/resources/fonts/NotoSansSC-Regular.ttf',
  'api/resources/fonts/NotoSansSC-Bold.ttf',
]
export default defineConfig({
  root: fileURLToPath(new URL('.', import.meta.url)),
  publicDir: root + '/public',
  plugins: [
    vue(),
    {
      name: 'prototype-font-cache',
      generateBundle() {
        for (const [name, file] of Object.entries(licenses))
          this.emitFile({
            type: 'asset',
            fileName: 'font-licenses/' + name,
            source: readFileSync(root + '/' + file),
          })
      },
      configureServer(server) {
        server.middlewares.use((request, response, next) => {
          const license = licenses[request.url?.replace('/font-licenses/', '')]
          if (request.url?.startsWith('/font-licenses/') && license) {
            response.setHeader('Content-Type', 'text/plain; charset=utf-8')
            response.end(readFileSync(root + '/' + license))
            return
          }
          const font = fontPaths.find(
            (file) => request.url?.split('?')[0] === '/@fs' + root + '/' + file,
          )
          if (font) {
            response.setHeader('Content-Type', 'font/ttf')
            response.setHeader('Content-Length', statSync(root + '/' + font).size)
            response.setHeader('Cache-Control', 'public, max-age=31536000, immutable')
            createReadStream(root + '/' + font).pipe(response)
            return
          }
          next()
        })
      },
    },
  ],
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  worker: { format: 'es' },
  server: {
    host: '127.0.0.1',
    port: 5186,
    strictPort: true,
    fs: {
      allow: [
        root + '/src',
        root + '/public',
        root + '/preview/browser-pdf',
        root + '/node_modules',
        root + '/api/vendor/dompdf/dompdf/lib/fonts',
        root + '/api/resources/fonts',
      ],
    },
    proxy: {
      '/api': { target: process.env.API_PROXY_TARGET || 'http://127.0.0.1:8000' },
      '/access/': { target: process.env.API_PROXY_TARGET || 'http://127.0.0.1:8000' },
    },
  },
  build: { outDir: '../../artifacts/milestone-6-1/prototype-build', emptyOutDir: true },
})
