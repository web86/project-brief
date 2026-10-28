import test from 'node:test'
import assert from 'node:assert/strict'
import { documentDefinition, textRuns, needsCjk } from '../src/services/pdf/definition.js'
import { createBrowserPdfAdapter } from '../src/services/pdf/index.js'
import { loadPublicFont } from '../src/services/pdf/font-cache.js'

const document = {
  title: '技术需求 / Çığ / ТЗ',
  project: { name: 'Original project', website: 'https://example.test' },
  generated: { date: '26 October 2026' },
  labels: {
    project: 'Project',
    website: 'Website',
    generated: 'Generated',
    footer: 'ProjectBrief',
    attachments: 'Attachments',
    summary: 'SUMMARY',
  },
  sections: [
    {
      number: 2,
      name: 'Original section',
      tasks: [
        {
          number: '2.9',
          title: 'User task',
          content: [
            {
              key: 'description',
              block: true,
              label: 'Description',
              value: 'Keep <img src="x"> & 原文，不翻译。',
            },
            { block: false, label: 'Price', value: '1.234,50 USD' },
          ],
          metadata: 'Price: 1.234,50 USD',
          attachments: ['Original.txt'],
        },
      ],
    },
  ],
  summary: [{ label: 'Total', value: '1.234,50 USD', coverage: 'Provided for 1 of 2 tasks' }],
  presentation: { pageBreakBySection: false },
}

test('browser layout preserves server strings, numbers, original text and mixed-script glyph runs', () => {
  const definition = documentDefinition(document)
  const serialized = JSON.stringify(definition)
  for (const value of [
    'Original section',
    '2.9',
    'User task',
    '1.234,50 USD',
    'Provided for 1 of 2 tasks',
    'Original.txt',
    '<img src=',
  ])
    assert.ok(serialized.includes(value), value)
  assert.equal(definition.pageSize, 'A4')
  assert.equal(
    textRuns('Çığ ışığı İpek — 简体中文，原文。 русский')
      .map((run) => run.text)
      .join(''),
    'Çığ ışığı İpek — 简体中文，原文。 русский',
  )
  assert.equal(needsCjk(document), true)
  assert.equal(needsCjk({ text: 'Çığ ışığı İpek · русский' }), false)
  assert.ok(textRuns('中文。').every((run) => run.font === 'NotoSC'))
  assert.equal(JSON.stringify(definition.footer(2, 9)).includes('2 / 9'), true)
  const following = (...nodes) => ({ getFollowingNodesOnPage: () => nodes })
  assert.equal(
    definition.pageBreakBefore(
      { headlineLevel: 2 },
      following({ id: 'footer' }, { id: 'page-number' }),
    ),
    true,
  )
  assert.equal(definition.pageBreakBefore({ headlineLevel: 2 }, following({ id: 'body-7' })), false)
})

test('PDF worker is terminated on success and private JSON is sent only to it', async () => {
  let terminated = false,
    requestPath
  const worker = {
    postMessage(data) {
      assert.deepEqual(data, { document })
      queueMicrotask(() =>
        this.onmessage({
          data: { stage: 'complete', buffer: new ArrayBuffer(5), metrics: { renderMs: 1 } },
        }),
      )
    },
    terminate() {
      terminated = true
    },
  }
  const adapter = createBrowserPdfAdapter({
    request: async (path, options) => {
      requestPath = path
      assert.equal(options.headers['X-ProjectBrief-Pdf-Renderer'], '2')
      return { schemaVersion: 1, document, filename: 'safe.pdf' }
    },
    workerFactory: () => worker,
  })
  const result = await adapter({ projectId: 'project', client: true, options: { locale: 'zh-CN' } })
  assert.equal(requestPath, '/api/client/project/project/export-data')
  assert.equal(result.blob.type, 'application/pdf')
  assert.equal(terminated, true)
})

test('large or line-heavy original blocks remain breakable while short headings stay with text', () => {
  const short = documentDefinition(document)
  assert.ok(short.content.some((node) => node.unbreakable && node.stack))
  for (const value of ['中'.repeat(10000), '\r'.repeat(10), '\t'.repeat(300)]) {
    const input = structuredClone(document)
    input.sections[0].tasks[0].content[0].value = value
    const definition = documentDefinition(input)
    const block = definition.content.find(
      (node) => node.text?.map((run) => run.text).join('') === value,
    )
    if (!value.includes('\r')) {
      assert.ok(block, 'original block must remain outside an unbreakable group')
      assert.equal(block.unbreakable, undefined)
    } else {
      assert.ok(
        definition.content.filter((node) => node.text?.map((run) => run.text).join('') === ' ')
          .length >= 9,
        'Explicit blank paragraphs survive in the breakable flow',
      )
    }
  }
})

const descendants = (nodes) =>
  nodes.flatMap((node) => [
    node,
    ...descendants(node.stack || node.columns || node.table?.body.flat() || []),
  ])

test('compact document has no description caption, preserves inline block labels and prints metadata once', () => {
  const input = structuredClone(document)
  input.sections[0].tasks[0].content.push({
    key: 'expectedResult',
    block: true,
    label: 'Expected result',
    value: 'Original result\nSecond line',
  })
  input.sections[0].tasks[0].metadata = 'Approved · Priority: Normal · 2 h · 150 $'
  const definition = documentDefinition(input)
  const all = descendants(definition.content)
  const strings = all
    .filter((node) => node.text)
    .map((node) => node.text.map((run) => run.text).join(''))
  assert.ok(!strings.includes('Description'))
  assert.ok(strings.includes('Keep <img src="x"> & 原文，不翻译。'))
  assert.ok(strings.includes('Expected result: Original result'))
  assert.ok(strings.includes('Second line'))
  assert.equal(strings.filter((value) => value.includes('2 h')).length, 1)
  assert.equal(definition.defaultStyle.fontSize, 9.25)
  assert.ok(definition.pageMargins.slice(0, 3).every((value) => value >= 45 && value <= 51))
  assert.ok(all.every((node) => !node.fillColor))
})

test('SVG logo is embedded directly once and disabled private branding stays absent', () => {
  const input = structuredClone(document)
  input.branding = {
    logoSvg:
      '<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg"><rect width="20" height="20"/></svg>',
    name: 'Studio',
    business: [],
    platform: false,
  }
  const definition = documentDefinition(input)
  const all = descendants(definition.content)
  assert.equal(all.filter((node) => node.svg).length, 1)
  assert.equal(all.filter((node) => node.image).length, 0)
  assert.deepEqual(all.find((node) => node.svg).fit, [86, 28])
  assert.ok(!JSON.stringify(definition.footer(1, 2)).includes('ProjectBrief'))
  assert.ok(JSON.stringify(definition.footer(1, 2)).includes('Studio'))
  assert.equal(definition.info.creator, input.title)
})

test('section breaks precede only later sections and long content is never boxed as a whole task', () => {
  const input = structuredClone(document)
  input.sections.push(structuredClone(input.sections[0]))
  input.presentation.pageBreakBySection = true
  let definition = documentDefinition(input)
  assert.equal(definition.content.filter((node) => node.pageBreak === 'before').length, 1)
  input.sections[0].tasks[0].content[0].value = 'Long paragraph '.repeat(2000)
  definition = documentDefinition(input)
  const paragraph = definition.content.find(
    (node) =>
      node.text?.map((run) => run.text).join('') === input.sections[0].tasks[0].content[0].value,
  )
  assert.ok(paragraph)
  assert.equal(paragraph.unbreakable, undefined)
  assert.equal(
    definition.pageBreakBefore(
      { headlineLevel: 2 },
      { getFollowingNodesOnPage: () => [{ id: 'body-start', startPosition: { top: 777 } }] },
    ),
    true,
  )
})

test('public font cache avoids another transfer and unavailable storage does not prevent export', async () => {
  const entries = new Map()
  let requests = 0
  const cache = {
    match: async (url) => entries.get(url)?.clone(),
    put: async (url, response) => entries.set(url, response),
  }
  const request = async () => {
    requests++
    return new Response('synthetic font')
  }
  const options = { storage: { open: async () => cache }, request }
  assert.equal((await loadPublicFont('/fonts/public.ttf', options)).cached, false)
  assert.equal((await loadPublicFont('/fonts/public.ttf', options)).cached, true)
  assert.equal(requests, 1)
  const result = await loadPublicFont('/fonts/public.ttf', {
    storage: {
      open: async () => {
        throw new Error('storage unavailable')
      },
    },
    request,
  })
  assert.equal(await result.response.text(), 'synthetic font')
})

test('PDF cancellation terminates the worker and allows another operation', async () => {
  const controller = new AbortController()
  let terminated = false
  const worker = {
    postMessage() {
      queueMicrotask(() => controller.abort())
    },
    terminate() {
      terminated = true
    },
  }
  const adapter = createBrowserPdfAdapter({
    request: async () => ({ schemaVersion: 1, document, filename: 'safe.pdf' }),
    workerFactory: () => worker,
  })
  await assert.rejects(
    adapter({ projectId: 'p', options: {}, signal: controller.signal }),
    (error) => error.name === 'AbortError',
  )
  assert.equal(terminated, true)
})

test('a stalled worker times out and releases its resources', async (context) => {
  context.mock.timers.enable({ apis: ['setTimeout'] })
  let terminated = false
  const adapter = createBrowserPdfAdapter({
    request: async () => ({ schemaVersion: 1, document, filename: 'safe.pdf' }),
    workerFactory: () => ({
      postMessage() {},
      terminate() {
        terminated = true
      },
    }),
  })
  const pending = adapter({ projectId: 'p', options: {} })
  await Promise.resolve()
  const rejected = assert.rejects(pending, /pdf_generation_timeout/)
  context.mock.timers.tick(120001)
  await rejected
  assert.equal(terminated, true)
})
