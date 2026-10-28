import assert from 'node:assert/strict'
import { readFile, writeFile } from 'node:fs/promises'
import { pathToFileURL } from 'node:url'
import path from 'node:path'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const directory = process.env.BRANDING_EXPORT_QA_DIRECTORY
  ? pathToFileURL(path.resolve(process.env.BRANDING_EXPORT_QA_DIRECTORY) + path.sep)
  : new URL('../artifacts/milestone-7-5/exports/', import.meta.url)
const server = await createServer({
  mode: 'test',
  plugins: [
    {
      name: 'isolated-export-qa-page',
      configureServer(server) {
        server.middlewares.use((request, response, next) => {
          if (request.url === '/qa-export') {
            response.setHeader('Content-Type', 'text/html')
            response.end(
              '<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>',
            )
          } else next()
        })
      },
    },
  ],
  server: { host: '127.0.0.1', port: 5197, strictPort: true, hmr: false },
  logLevel: 'error',
})
let browser
try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()
  await page.route('**/api/resources/fonts/*.ttf*', async (route) => {
    const name = new URL(route.request().url()).pathname.split('/').at(-1)
    await route.fulfill({
      body: await readFile(new URL('../api/resources/fonts/' + name, import.meta.url)),
      contentType: 'font/ttf',
    })
  })
  await page.goto('http://127.0.0.1:5197/qa-export')
  for (const name of process.env.BRANDING_EXPORT_NAMES?.split(',') || [
    'branded-ru',
    'branded-en',
    'branded-tr',
    'branded-zh-CN',
    'unbranded',
  ]) {
    const payload = JSON.parse(await readFile(new URL(name + '.json', directory), 'utf8'))
    const result = await page.evaluate(async (payload) => {
      const { createBrowserPdfAdapter } = await import('/src/services/pdf/index.js')
      const file = await createBrowserPdfAdapter({ request: async () => payload })({
        projectId: 'qa',
      })
      return {
        bytes: Array.from(new Uint8Array(await file.blob.arrayBuffer())),
        metrics: file.metrics,
      }
    }, payload)
    const bytes = Buffer.from(result.bytes)
    assert.equal(bytes.subarray(0, 5).toString(), '%PDF-')
    await writeFile(new URL(name + '.pdf', directory), bytes)
    console.log('PASS', name, bytes.length, 'bytes; actual browser PDF worker')
  }
} finally {
  await browser?.close()
  await server.close()
}
