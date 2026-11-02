import { createServer } from 'node:http'
import { readFile, stat } from 'node:fs/promises'
import path from 'node:path'
const types = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json',
  '.webmanifest': 'application/manifest+json',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.webp': 'image/webp',
  '.txt': 'text/plain; charset=utf-8',
}
export function staticDemoServer(directory) {
  const root = path.resolve(directory)
  return createServer(async (request, response) => {
    try {
      const pathname = decodeURIComponent(new URL(request.url, 'http://localhost').pathname)
      if (
        !['GET', 'HEAD'].includes(request.method) ||
        /^\/(api|platform|access|client-sso)(\/|$)/.test(pathname)
      ) {
        response.writeHead(404)
        response.end()
        return
      }
      let file = path.resolve(root, '.' + pathname)
      if (!file.startsWith(root + path.sep) && file !== root) throw new Error('Invalid path')
      const found = await stat(file).catch(() => null)
      if (!found?.isFile()) {
        if (
          /^\/(assets|snapshots|demo-assets|schemas)\//.test(pathname) ||
          path.extname(pathname)
        ) {
          response.writeHead(404)
          response.end()
          return
        }
        file = path.join(root, 'index.html')
      }
      const bytes = await readFile(file)
      response.writeHead(200, {
        'Content-Type': types[path.extname(file)] || 'application/octet-stream',
        'X-Content-Type-Options': 'nosniff',
        'Cache-Control': /(?:-[A-Za-z0-9_-]{8,}|[a-f0-9]{64})\.[a-z]+$/.test(file)
          ? 'public, max-age=31536000, immutable'
          : 'no-cache, must-revalidate',
        'Referrer-Policy': 'no-referrer',
      })
      response.end(request.method === 'HEAD' ? undefined : bytes)
    } catch {
      response.writeHead(404)
      response.end()
    }
  })
}
if (process.argv[1] === new URL(import.meta.url).pathname) {
  const server = staticDemoServer(process.argv[2] || '.tools/static-demo-build')
  server.listen(Number(process.env.STATIC_DEMO_PORT || 18082), '127.0.0.1', () =>
    console.log(`Static Demo: http://127.0.0.1:${server.address().port}/demo`),
  )
}
