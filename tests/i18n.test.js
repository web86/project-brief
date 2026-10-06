import { test, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import ru from '../src/locales/ru.js'
import en from '../src/locales/en.js'
import {
  LOCALE_KEY,
  resolveLocale,
  initializeLocale,
  setManualLocale,
  applyClientLocale,
  locale,
  t,
  formatDate,
  formatCurrency,
  formatApiError,
} from '../src/i18n/index.js'
const flatten = (value, prefix = '') =>
  Object.entries(value).flatMap(([key, item]) =>
    typeof item === 'string' ? [prefix + key] : flatten(item, prefix + key + '.'),
  )
let values
beforeEach(() => {
  values = new Map()
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (key) => values.get(key) || null,
      setItem: (key, value) => values.set(key, value),
    },
  })
  Object.defineProperty(globalThis, 'document', {
    configurable: true,
    value: { documentElement: { lang: '' } },
  })
  initializeLocale({ browser: { languages: ['en-GB'] } })
})
test('RU and EN have exact key parity and all static translation references exist', () => {
  assert.deepEqual(flatten(ru).sort(), flatten(en).sort())
  const keys = new Set(flatten(en))
  const audit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const path = directory + '/' + entry.name
      if (entry.isDirectory() && !['locales', 'data', 'assets'].includes(entry.name)) audit(path)
      else if (entry.isFile() && /\.(vue|js)$/.test(path)) {
        const text = readFileSync(path, 'utf8')
        for (const match of text.matchAll(/\bt\(['"]((?:ui|common|counts|flow)\.[^'"]+)['"]/g))
          assert.ok(keys.has(match[1]), `${path}: missing ${match[1]}`)
      }
    }
  }
  audit('src')
})
test('browser detection maps Russian to RU and other languages to EN with language fallback', () => {
  assert.equal(resolveLocale({ languages: ['ru-RU', 'en'] }), 'ru')
  assert.equal(resolveLocale({ languages: ['en-GB', 'ru'] }), 'en')
  for (const language of ['tr-TR', 'de-DE', 'fr', 'zh-CN'])
    assert.equal(resolveLocale({ languages: [language] }), 'en')
  assert.equal(resolveLocale({ languages: [], language: 'ru' }), 'ru')
  assert.equal(resolveLocale({}), 'en')
})
test('manual locale has priority, survives reinitialization and updates HTML lang', () => {
  initializeLocale({ browser: { languages: ['ru-RU'] }, preferred: 'en' })
  assert.equal(locale.value, 'en')
  assert.equal(setManualLocale('ru'), true)
  assert.equal(document.documentElement.lang, 'ru')
  assert.equal(values.get(LOCALE_KEY), 'ru')
  assert.equal(initializeLocale({ browser: { languages: ['en'] }, preferred: 'en' }), 'ru')
  applyClientLocale('en')
  assert.equal(locale.value, 'ru')
  assert.equal(setManualLocale('tr'), false)
  assert.equal(locale.value, 'ru')
})
test('client preference precedes browser without persisting detected locale', () => {
  assert.equal(initializeLocale({ browser: { languages: ['ru'] }, preferred: 'en' }), 'en')
  assert.equal(values.has(LOCALE_KEY), false)
  assert.equal(initializeLocale({ browser: { languages: ['tr'] }, preferred: 'ru' }), 'ru')
  assert.equal(initializeLocale({ browser: { languages: ['tr'] }, preferred: 'tr' }), 'en')
})
test('dates, currency, pluralization, errors and confirmation react to locale changes', () => {
  locale.value = 'ru'
  assert.match(formatDate('2026-10-05T12:00:00Z'), /октября/)
  assert.match(formatCurrency(1234.5, { currency: 'RUB' }), /1.?234,50/)
  for (const [count, noun] of [
    [1, 'идея'],
    [2, 'идеи'],
    [5, 'идей'],
    [11, 'идей'],
    [21, 'идея'],
    [22, 'идеи'],
  ])
    assert.equal(t('counts.ideas', count), noun)
  assert.equal(t('flow.deleteTitle'), 'Удалить эту идею?')
  locale.value = 'en'
  assert.match(formatDate('2026-10-05T12:00:00Z'), /October/)
  assert.match(formatCurrency(1234.5, { currency: 'USD' }), /1,234\.50/)
  assert.equal(t('counts.ideas', 1), 'idea')
  assert.equal(t('counts.ideas', 2), 'ideas')
  assert.equal(t('flow.deleteTitle'), 'Delete this idea?')
  assert.equal(t('flow.deleteIdea'), 'Delete idea')
  assert.equal(
    formatApiError({ status: 422, errors: { name: ['Russian server text'] } }),
    'Check the fields.',
  )
})
