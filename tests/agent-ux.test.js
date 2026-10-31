import { test, before, after } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import {
  agentIcon,
  agentCredentials,
  latestAgentReport,
} from '../src/services/agentPresentation.js'
import en from '../src/locales/en.js'
import ru from '../src/locales/ru.js'
import tr from '../src/locales/tr.js'
import zh from '../src/locales/zh-CN.js'
let server, Panel, i18n
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
  })
  Panel = (await server.ssrLoadModule('/src/components/TaskAgents.vue')).default
  i18n = (await server.ssrLoadModule('/src/i18n/index.js')).i18n
  i18n.global.locale.value = 'en'
})
after(async () => server?.close())
test('credential presentation distinguishes unknown, issued, revoked and expired without claiming connectivity', () => {
  assert.equal(agentCredentials({}), null)
  assert.equal(agentCredentials({ tokens: [] }), 'missing')
  const now = Date.parse('2026-10-31T00:00:00Z')
  assert.equal(agentCredentials({ tokens: [{ expiresAt: '2026-11-01 00:00:00' }] }, now), 'issued')
  assert.equal(
    agentCredentials({ tokens: [{ expiresAt: '2026-10-30T00:00:00Z' }] }, now),
    'missing',
  )
  assert.equal(agentCredentials({ tokens: [{ revokedAt: '2026-10-30' }] }, now), 'missing')
  assert.deepEqual(['ai', 'automation', 'service'].map(agentIcon), ['bot', 'settings', 'globe'])
})
test('latest report links to its own full comment and tolerates unavailable comments', () => {
  const assignment = { reports: [{ id: 'old' }, { id: 'latest' }] }
  const task = {
    comments: [
      { id: 'c1', text: 'old', agentReport: { id: 'old' } },
      { id: 'c2', text: 'latest text', agentReport: { id: 'latest' } },
    ],
  }
  assert.deepEqual(latestAgentReport(task, assignment), {
    id: 'latest',
    commentId: 'c2',
    summary: 'latest text',
  })
  assert.equal(latestAgentReport({}, {}), null)
  assert.equal(
    latestAgentReport({}, { reports: [{ createdAt: '2026-10-31 12:00:00' }] }).createdAt,
    '2026-10-31T12:00:00Z',
  )
})
for (const state of ['pending', 'running', 'submitted', 'failed', 'cancelled'])
  test(`task panel separates ${state} execution from registration and shows only latest report`, async () => {
    const task = {
      id: 'task',
      agentAssignments: [
        {
          id: 'a',
          state,
          agent: { id: 'agent', name: 'Executor', kind: 'automation', active: false },
          reports: [
            { id: 'old', state: 'running' },
            {
              id: 'last',
              state,
              createdAt: '2026-10-31T12:00:00Z',
              pullRequestUrl: 'https://example.test/pr',
              resultUrl: 'https://example.test/result',
            },
          ],
        },
      ],
      comments: [
        { id: 'old-comment', text: 'OLDER_REPORT_DO_NOT_DUPLICATE', agentReport: { id: 'old' } },
        {
          id: 'full-comment',
          text: '<script>unsafe</script> Latest result',
          agentReport: { id: 'last' },
        },
      ],
    }
    const html = await renderToString(
      createSSRApp(Panel, { task, projectId: 'project', agents: [] }),
    )
    assert.ok(html.includes(en.agents.executionState[state]))
    assert.ok(html.includes('Disabled'))
    assert.ok(html.includes('href="#comment-full-comment"'))
    assert.ok(html.includes('https://example.test/pr'))
    assert.ok(html.includes('&lt;script&gt;unsafe&lt;/script&gt;'))
    assert.ok(!html.includes('OLDER_REPORT_DO_NOT_DUPLICATE'))
    assert.ok(!html.includes('>Run<'))
  })
test('all four locales provide the same agent interface keys', () => {
  const keys = (object) =>
    Object.entries(object)
      .flatMap(([key, value]) =>
        typeof value === 'object' ? keys(value).map((child) => key + '.' + child) : [key],
      )
      .sort()
  for (const locale of [ru, tr, zh]) assert.deepEqual(keys(locale.agents), keys(en.agents))
})
