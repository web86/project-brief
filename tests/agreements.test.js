import { test, before, after } from 'node:test'
import assert from 'node:assert/strict'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createPinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createServer } from 'vite'
import { safeFooterUrl } from '../src/utils/agreementEditor.js'
import { appDestination } from '../src/services/session.js'
import { isSensitiveMutation, isSecurityConfirmationScreen } from '../src/services/stepUp.js'
let server, Document, Footer, Status, i18n
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
    mode: 'test',
  })
  Document = (await server.ssrLoadModule('/src/components/AgreementDocument.vue')).default
  Footer = (await server.ssrLoadModule('/src/components/AppFooter.vue')).default
  Status = (await server.ssrLoadModule('/src/components/AgreementStatus.vue')).default
  i18n = (await server.ssrLoadModule('/src/i18n/index.js')).i18n
})
after(async () => {
  await server?.close()
})
async function render(component, props, language = 'en') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component }],
  })
  await router.push('/w/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/agreement')
  i18n.global.locale.value = language
  return renderToString(createSSRApp(component, props).use(createPinia()).use(router).use(i18n))
}
test('agreement gate follows MFA and precedes workspace recommendations and platform routing', async () => {
  assert.equal(
    await appDestination(async () => ({
      authenticated: true,
      type: 'admin',
      agreementRequired: true,
      securityRecommended: true,
    })),
    '/admin/agreement',
  )
  assert.equal(
    await appDestination(async () => ({
      authenticated: false,
      state: 'pending_2fa',
      agreementRequired: true,
    })),
    '/admin/security',
  )
  assert.equal(
    await appDestination(async () => ({
      authenticated: true,
      type: 'platform',
      agreementRequired: true,
    })),
    '/admin/agreement',
  )
  assert.equal(isSensitiveMutation('/api/admin/settings/agreement', 'PUT'), true)
  assert.equal(isSecurityConfirmationScreen('/admin/settings/agreement'), true)
  assert.equal(isSensitiveMutation('/api/admin/agreement/accept', 'POST'), false)
})
test('document renderer escapes all text and renders only known formatting tags', async () => {
  const html = await render(Document, {
    blocks: [
      { type: 'heading', content: [{ text: 'Terms <script>alert(1)</script>', bold: true }] },
      { type: 'bullet', content: [{ text: 'A & B', italic: true }] },
      { type: 'iframe', content: [{ text: 'javascript:alert(1)' }] },
    ],
  })
  assert.ok(html.includes('&lt;script&gt;'))
  assert.ok(html.includes('<h2><strong>'))
  assert.ok(html.includes('<ul><li><em>A &amp; B</em></li></ul>'))
  assert.ok(!html.includes('<script>'))
  assert.ok(!html.includes('<iframe'))
})
test('footer uses only workspace public fields, safe URLs and no empty or fictional legal links', async () => {
  for (const value of [
    'javascript:alert(1)',
    'data:text/html,test',
    '//evil.test',
    'https://u:p@example.test',
    'https://example.test\\@evil.test',
  ])
    assert.equal(safeFooterUrl(value), null)
  const html = await render(Footer, {
    branding: {
      companyName: '<img src=x onerror=alert(1)>',
      companyUrl: 'javascript:alert(1)',
      supportEmail: 'help@example.test',
      agreementUrl: '/w/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/agreement',
      account: 'SENSITIVE BANK ACCOUNT',
      address: 'PRIVATE ADDRESS',
    },
  })
  assert.ok(html.includes('&lt;img'))
  assert.ok(!html.includes('<img'))
  assert.ok(html.includes('mailto:help@example.test'))
  assert.ok(html.includes('Rules and agreement'))
  assert.ok(!html.includes('SENSITIVE BANK ACCOUNT'))
  assert.ok(!html.includes('PRIVATE ADDRESS'))
  assert.ok(!html.includes('Powered by'))
  const defaults = await render(Footer, {})
  assert.ok(defaults.includes('Project Brief'))
  assert.ok(!defaults.includes('<nav'))
})
test('agreement status is compact, localized in four languages and contains acceptance evidence only when present', async () => {
  for (const locale of ['ru', 'en', 'tr', 'zh-CN']) {
    const hidden = await render(Status, { agreement: { status: 'notRequired' } }, locale)
    assert.ok(!hidden.includes('agreement-status'))
    for (const status of ['pending', 'renewal', 'accepted']) {
      const html = await render(
        Status,
        {
          agreement: {
            status,
            version: '2026-10-30',
            locale: 'tr',
            acceptedAt: '2026-10-30 12:00:00',
          },
          expanded: true,
        },
        locale,
      )
      assert.ok(html.includes('2026-10-30'))
      assert.ok(html.includes('tr'))
      assert.ok(!html.includes('agreement.' + status))
    }
  }
})

test('agreement links render escaped text and fixed safe attributes only', async () => {
  for (const language of ['ru', 'en', 'tr', 'zh-CN']) {
    const html = await render(
      Document,
      {
        blocks: [
          {
            type: 'paragraph',
            content: [
              {
                text: '<img onerror=alert(1)>',
                href: 'https://example.test/rules?a=1&b=2',
                bold: true,
                italic: true,
              },
              { text: 'Mail', href: 'mailto:help@example.test' },
              { text: 'Unsafe', href: 'javascript:alert(1)', onclick: 'alert(1)' },
            ],
          },
        ],
      },
      language,
    )
    assert.ok(html.includes('href="https://example.test/rules?a=1&amp;b=2"'))
    assert.ok(html.includes('target="_blank" rel="noopener noreferrer"'))
    assert.ok(html.includes('<strong><em>&lt;img'))
    assert.ok(html.includes('href="mailto:help@example.test"'))
    assert.ok(!html.includes('javascript:'))
    assert.ok(!html.includes('onclick='))
    assert.ok(!html.includes('<img'))
  }
})
test('agreement URL allowlist blocks active schemes, credentials, controls and email headers', async () => {
  const { safeAgreementUrl } = await import('../src/utils/agreementEditor.js')
  for (const value of [
    'https://example.test/rules',
    'http://example.test/',
    'mailto:help@example.test',
  ])
    assert.equal(safeAgreementUrl(value), value)
  for (const value of [
    '',
    null,
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    'data:text/html,test',
    'vbscript:msgbox(1)',
    '//evil.test',
    '/relative',
    'https:///evil.test',
    'https://u:p@example.test',
    'https://example.test\\@evil.test',
    'java\nscript:alert(1)',
    'https://example.test/\u0000',
    'mailto:a@example.test?subject=x',
    'mailto:a@example.test%0aBcc:evil@example.test',
  ])
    assert.equal(safeAgreementUrl(value), null, String(value))
})
test('adjacent list items retain one list and ordered numbering after a document reload', async () => {
  const html = await render(Document, {
    blocks: [
      { type: 'number', content: [{ text: 'One' }] },
      { type: 'number', content: [{ text: 'Two', bold: true }] },
      { type: 'paragraph', content: [{ text: 'Between' }] },
      { type: 'bullet', content: [{ text: 'Three' }] },
      { type: 'bullet', content: [{ text: 'Four' }] },
    ],
  })
  assert.ok(html.includes('<ol><li>One</li><li><strong>Two</strong></li></ol>'))
  assert.ok(html.includes('<ul><li>Three</li><li>Four</li></ul>'))
})
