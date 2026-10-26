import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
const licenses = {
  'NotoSansSC-OFL.txt': '../api/resources/fonts/NotoSansSC-OFL.txt',
  'DejaVu-LICENSE.txt': '../api/resources/fonts/DejaVu-LICENSE.txt',
  'pdfmake-MIT.txt': '../node_modules/pdfmake/LICENSE',
}
export function pdfAssets() {
  return {
    name: 'pdf-font-licenses',
    generateBundle() {
      for (const [name, file] of Object.entries(licenses))
        this.emitFile({
          type: 'asset',
          fileName: 'font-licenses/' + name,
          source: readFileSync(new URL(file, import.meta.url)),
        })
    },
    configureServer(server) {
      server.middlewares.use((request, response, next) => {
        const file = licenses[request.url?.replace('/font-licenses/', '')]
        if (request.url?.startsWith('/font-licenses/') && file) {
          response.setHeader('Content-Type', 'text/plain; charset=utf-8')
          response.end(readFileSync(fileURLToPath(new URL(file, import.meta.url))))
        } else next()
      })
    },
  }
}
