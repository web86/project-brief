import { readAgreementEditor } from '../src/utils/agreementEditor.js'
import test from 'node:test'
import assert from 'node:assert/strict'
import { agreementLanguage, documentLanguages } from '../src/utils/agreementLanguages.js'

test('document language falls back without changing the interface locale', () => {
  const doc = { enabledLocales: ['ru'], defaultLocale: 'ru' }
  for (const locale of ['en', 'tr', 'zh-CN', 'ru'])
    assert.equal(agreementLanguage(doc, locale), 'ru')
  assert.equal(agreementLanguage({ enabledLocales: ['en', 'tr'], defaultLocale: 'tr' }, 'en'), 'en')
  assert.equal(
    agreementLanguage({ enabledLocales: ['en', 'tr'], defaultLocale: 'tr' }, 'zh-CN'),
    'tr',
  )
})
test('legacy published documents keep all four languages', () => {
  assert.deepEqual(documentLanguages({ texts: {} }), {
    enabled: ['ru', 'en', 'tr', 'zh-CN'],
    default: 'ru',
  })
})

test('native lists nested inside a replaced template heading retain blocks, links and formatting', () => {
  const text = (value) => ({ nodeType: 3, textContent: value })
  const el = (tagName, childNodes, attrs = {}) => ({
    nodeType: 1,
    tagName,
    childNodes,
    children: childNodes.filter((n) => n.nodeType === 1),
    getAttribute: (key) => attrs[key],
  })
  const root = {
    childNodes: [
      el('H1', [
        text('Title'),
        el('OL', [
          el('LI', [el('I', [el('A', [text('First')], { href: 'https://example.test/' })])]),
          el('LI', [text('Second')]),
        ]),
        text('Ending'),
      ]),
      el('SCRIPT', [text('danger')]),
    ],
  }
  assert.deepEqual(readAgreementEditor(root), [
    { type: 'heading', content: [{ text: 'Title' }] },
    { type: 'number', content: [{ text: 'First', italic: true, href: 'https://example.test/' }] },
    { type: 'number', content: [{ text: 'Second' }] },
    { type: 'heading', content: [{ text: 'Ending' }] },
  ])
})
