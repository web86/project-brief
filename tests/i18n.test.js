import { test, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import ru from '../src/locales/ru.js'
import en from '../src/locales/en.js'
import tr from '../src/locales/tr.js'
import { spawnSync } from 'node:child_process'
import {
  LOCALE_KEY,
  LANGUAGE_CATALOG,
  SUPPORTED_LOCALES,
  INTL_LOCALES,
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
test('RU, EN and TR have exact key parity and all static translation references exist', () => {
  assert.deepEqual(flatten(ru).sort(), flatten(en).sort())
  assert.deepEqual(flatten(tr).sort(), flatten(en).sort())
  const keys = new Set(flatten(en))
  const audit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const path = directory + '/' + entry.name
      if (entry.isDirectory() && !['locales', 'data', 'assets'].includes(entry.name)) audit(path)
      else if (entry.isFile() && /\.(vue|js)$/.test(path)) {
        const text = readFileSync(path, 'utf8')
        for (const match of text.matchAll(/\bt\(['"]([\w]+\.[^'"]+)['"]\s*[,)]/g))
          assert.ok(keys.has(match[1]), `${path}: missing ${match[1]}`)
      }
    }
  }
  audit('src')
})
test('browser detection maps RU, EN and TR and falls back to English', () => {
  assert.equal(resolveLocale({ languages: ['ru-RU', 'en'] }), 'ru')
  assert.equal(resolveLocale({ languages: ['en-GB', 'ru'] }), 'en')
  assert.equal(resolveLocale({ languages: ['tr-TR', 'en'] }), 'tr')
  for (const language of ['de-DE', 'fr', 'zh-TW', 'zh-Hant', 'zh-HK'])
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
  assert.equal(setManualLocale('de'), false)
  assert.equal(locale.value, 'ru')
})
test('client preference precedes browser without persisting detected locale', () => {
  assert.equal(initializeLocale({ browser: { languages: ['ru'] }, preferred: 'en' }), 'en')
  assert.equal(values.has(LOCALE_KEY), false)
  assert.equal(initializeLocale({ browser: { languages: ['tr'] }, preferred: 'ru' }), 'ru')
  assert.equal(initializeLocale({ browser: { languages: ['tr'] }, preferred: 'tr' }), 'tr')
})
test('language catalog supplies native names, complete messages and Intl locales in sync with Laravel', () => {
  assert.deepEqual(
    LANGUAGE_CATALOG.map(({ code, name }) => [code, name]),
    [
      ['ru', 'Русский'],
      ['en', 'English'],
      ['tr', 'Türkçe'],
      ['zh-CN', '简体中文'],
    ],
  )
  for (const language of LANGUAGE_CATALOG) {
    assert.equal(INTL_LOCALES[language.code], language.intl)
    assert.deepEqual(flatten(language.messages).sort(), flatten(en).sort())
  }
  const result = spawnSync(
    'php',
    [
      '-r',
      '$config = require "api/config/localization.php"; echo json_encode($config["languages"]);',
    ],
    { encoding: 'utf8' },
  )
  assert.equal(result.status, 0, result.stderr)
  const backend = JSON.parse(result.stdout)
  assert.deepEqual(Object.keys(backend).sort(), [...SUPPORTED_LOCALES].sort())
  for (const { code, intl, browserAliases = [] } of LANGUAGE_CATALOG) {
    assert.equal(backend[code].intl, intl)
    assert.deepEqual(backend[code].browser_aliases || [], browserAliases)
  }
})
test('unknown and corrupt locale choices fall back; blocked storage still allows live selection', () => {
  for (const value of ['de', '{broken', 'null', '__proto__']) {
    values.set(LOCALE_KEY, value)
    assert.equal(initializeLocale({ browser: { languages: ['de-DE'] } }), 'en')
    assert.equal(initializeLocale({ preferred: 'ru', browser: { language: 'en' } }), 'ru')
  }
  assert.equal(resolveLocale({ languages: ['rubbish'] }), 'en')
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    get() {
      throw new Error('SecurityError')
    },
  })
  assert.equal(initializeLocale({ browser: { language: 'ru-RU' } }), 'ru')
  setManualLocale('en')
  assert.equal(locale.value, 'en')
  assert.equal(document.documentElement.lang, 'en')
  applyClientLocale('ru')
  assert.equal(locale.value, 'en')
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
  assert.equal(t('flow.deleteTitle'), 'Переместить идею в корзину?')
  locale.value = 'en'
  assert.match(formatDate('2026-10-05T12:00:00Z'), /October/)
  assert.match(formatCurrency(1234.5, { currency: 'USD' }), /1,234\.50/)
  assert.equal(t('counts.ideas', 1), 'idea')
  assert.equal(t('counts.ideas', 2), 'ideas')
  assert.equal(t('flow.deleteTitle'), 'Move idea to trash?')
  assert.equal(t('flow.deleteIdea'), 'Delete idea')
  assert.equal(
    formatApiError({ status: 422, errors: { name: ['Russian server text'] } }),
    'Check the fields.',
  )
})

const entries = (value, prefix = '') =>
  Object.entries(value).flatMap(([key, item]) =>
    typeof item === 'string' ? [[prefix + key, item]] : entries(item, prefix + key + '.'),
  )
const parameters = (text) =>
  [...new Set([...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1]))].sort()
test('every translation is nonempty, preserves interpolation names and renders without technical keys', () => {
  const reference = Object.fromEntries(entries(en))
  for (const { code, messages } of LANGUAGE_CATALOG) {
    locale.value = code
    for (const [key, message] of entries(messages)) {
      assert.ok(message.trim(), `${code}.${key} is empty`)
      assert.deepEqual(parameters(message), parameters(reference[key]), `${code}.${key} parameters`)
      const params = Object.fromEntries(
        parameters(message).map((name) => [name, name === 'count' ? 5 : 'Özgür İpek']),
      )
      for (const count of [0, 1, 2, 5, 21]) {
        const rendered = t(key, params, count)
        assert.notEqual(rendered, key, `${code}.${key} leaked a technical key`)
        assert.doesNotMatch(rendered, /\{\w+\}/, `${code}.${key} unresolved interpolation`)
      }
    }
  }
})
test('Turkish formatting, plurals and casing preserve the project currency and user content', () => {
  locale.value = 'tr'
  assert.match(formatDate('2026-10-05T12:00:00Z'), /Ekim/)
  for (const currency of ['RUB', 'USD', 'EUR', 'TRY']) {
    assert.equal(
      formatCurrency(1234.5, { currency }),
      new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(1234.5),
    )
  }
  for (const count of [0, 1, 2, 5, 21]) assert.equal(t('counts.ideas', count), 'fikir')
  assert.equal('ipek ışık'.toLocaleUpperCase(INTL_LOCALES.tr), 'İPEK IŞIK')
  assert.equal('İPEK IŞIK'.toLocaleLowerCase(INTL_LOCALES.tr), 'ipek ışık')
  assert.equal(t('flow.linkFor', { name: 'İpek çğışöü' }), 'İpek çğışöü için bağlantı')
  assert.equal(
    formatApiError({ messageKey: 'unknown.technical_key', status: 500 }),
    t('ui.weCouldNotCompleteThisActionPleaseTry'),
  )
  locale.value = 'unsupported'
  assert.equal(t('account.title'), en.account.title)
})

test('Simplified Chinese browser aliases stay distinct from Traditional Chinese and manual preferences', () => {
  for (const tag of ['zh-CN', 'zh-cn', 'zh-Hans', 'zh-Hans-CN', 'zh-SG', 'zh_Hans_SG']) {
    assert.equal(resolveLocale({ languages: [tag] }), 'zh-CN', tag)
  }
  for (const tag of ['zh', 'zh-TW', 'zh-HK', 'zh-MO', 'zh-Hant', 'zh-Hant-CN', 'zh-Hant-TW']) {
    assert.equal(resolveLocale({ languages: [tag, 'zh-CN'] }), 'en', tag)
  }
  assert.equal(resolveLocale({ manual: 'zh-CN', preferred: 'ru', language: 'zh-Hant' }), 'zh-CN')
  assert.equal(initializeLocale({ preferred: 'zh-CN', browser: { language: 'zh-Hant' } }), 'zh-CN')
  assert.equal(setManualLocale('zh-CN'), true)
  assert.equal(values.get(LOCALE_KEY), 'zh-CN')
  assert.equal(initializeLocale({ preferred: 'en', browser: { language: 'en' } }), 'zh-CN')
  for (const count of [0, 1, 2, 5, 21]) assert.equal(t('counts.ideas', count), '条创意')
  assert.match(formatDate('2026-10-05T12:00:00Z'), /10月/)
  assert.equal(
    formatCurrency(1234.5, { currency: 'USD' }),
    new Intl.NumberFormat('zh-CN', { style: 'currency', currency: 'USD' }).format(1234.5),
  )
})
