import assert from 'node:assert/strict'
import { readFile, writeFile } from 'node:fs/promises'
import { pathToFileURL, fileURLToPath } from 'node:url'
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
      enforce: 'pre',
      async load(id) {
        if (
          process.env.BRANDING_EXPORT_BASELINE_DEFINITION &&
          id === fileURLToPath(new URL('../src/services/pdf/definition.js', import.meta.url))
        )
          return readFile(process.env.BRANDING_EXPORT_BASELINE_DEFINITION, 'utf8')
      },
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
  const measurements = []
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
    measurements.push({ name, bytes: bytes.length, ...result.metrics })
    console.log('PASS', name, bytes.length, 'bytes; actual browser PDF worker')
  }
  await writeFile(
    new URL('pdf-metrics.json', directory),
    JSON.stringify(measurements, null, 2) + '\n',
  )
  if (process.env.DOCUMENT_VECTOR_QA === '1') {
    const payload = JSON.parse(await readFile(new URL('small-en.json', directory), 'utf8'))
    payload.document.branding.logo = null
    payload.document.branding.logoSvg =
      '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="30" viewBox="0 0 100 30"><path fill="#17695d" d="M0 0h100v30H0z"/></svg>'
    const values = await page.evaluate(async (payload) => {
      const { createBrowserPdfAdapter } = await import('/src/services/pdf/index.js')
      const file = await createBrowserPdfAdapter({ request: async () => payload })({
        projectId: 'qa',
      })
      return Array.from(new Uint8Array(await file.blob.arrayBuffer()))
    }, payload)
    const vector = Buffer.from(values)
    assert.equal(vector.subarray(0, 5).toString(), '%PDF-')
    assert.doesNotMatch(vector.toString('latin1'), /\/Subtype\s*\/Image/)
    await writeFile(
      new URL('vector-result.json', directory),
      JSON.stringify({ passed: true, bytes: vector.length, imageXObjects: 0 }) + '\n',
    )
  }
} finally {
  await browser?.close()
  await server.close()
}
