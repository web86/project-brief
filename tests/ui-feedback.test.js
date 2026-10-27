import { test, before, after } from 'node:test'
import assert from 'node:assert/strict'
import { newestComments } from '../src/utils/comments.js'
import { createServer } from 'vite'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
let server, RequestButton
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
    mode: 'test',
  })
  RequestButton = (await server.ssrLoadModule('/src/components/FeedbackRequestButton.vue')).default
  const { i18n } = await server.ssrLoadModule('/src/i18n/index.js')
  i18n.global.locale.value = 'en'
})
after(async () => server?.close())

test('idea comments use newest-first timestamps and persistent sequence for ties without mutating source', () => {
  const comments = [
    { id: 'late-uuid-a', sequence: 42, createdAt: '2026-10-27T10:00:00Z' },
    { id: 'early-uuid-z', sequence: 40, createdAt: '2026-10-26T10:00:00Z' },
    { id: 'late-uuid-z', sequence: 41, createdAt: '2026-10-27T10:00:00Z' },
  ]
  const original = structuredClone(comments)
  assert.deepEqual(
    newestComments(comments).map((comment) => comment.sequence),
    [42, 41, 40],
  )
  assert.deepEqual(comments, original)
  assert.deepEqual(
    newestComments([...comments].reverse()).map((comment) => comment.sequence),
    [42, 41, 40],
  )
})

test('large idea threads retain every comment and demo comments use stable insertion order for ties', () => {
  const comments = Array.from({ length: 1000 }, (_, id) => ({
    id,
    createdAt: '2026-10-27T10:00:00Z',
  }))
  const sorted = newestComments(comments)
  assert.equal(sorted.length, 1000)
  assert.equal(new Set(sorted.map((comment) => comment.id)).size, 1000)
  assert.equal(sorted[0].id, 999)
  assert.deepEqual(newestComments(), [])
})

test('feedback request buttons follow server completion and become disabled once requested or submitted', async () => {
  for (const [feedback, visible, text, disabled] of [
    [{ eligible: false }, false],
    [{ eligible: true }, true, 'Request feedback', false],
    [{ eligible: true, requested: true }, true, 'Request sent', true],
    [{ eligible: false, submitted: true }, true, 'Feedback sent', true],
  ]) {
    const html = await renderToString(
      createSSRApp(RequestButton, { project: { id: 'project', feedback } }),
    )
    assert.equal(html.includes('<button'), visible)
    if (visible) {
      assert.ok(html.includes(text))
      assert.equal(html.includes(' disabled'), disabled)
    }
  }
})
