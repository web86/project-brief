import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { createThemePreferences, THEME_KEY } from '../src/services/theme.js'
import { useProjectStore } from '../src/stores/project.js'
import { STORAGE_KEY } from '../src/constants/project.js'

function browser({ stored, dark = false, failRead = false, failWrite = false } = {}) {
  const values = new Map(stored === undefined ? [] : [[THEME_KEY, stored]])
  const listeners = new Set()
  const media = {
    matches: dark,
    addEventListener: (event, fn) => listeners.add(fn),
    removeEventListener: (event, fn) => listeners.delete(fn),
  }
  const meta = {
    setAttribute: (key, value) => {
      meta[key] = value
    },
  }
  const env = {
    localStorage: {
      getItem(key) {
        if (failRead) throw new Error('SecurityError')
        return values.get(key) ?? null
      },
      setItem(key, value) {
        if (failWrite) throw new Error('QuotaExceededError')
        values.set(key, value)
      },
    },
    document: { documentElement: { dataset: {}, style: {} }, querySelector: () => meta },
    matchMedia: (query) => {
      assert.equal(query, '(prefers-color-scheme: dark)')
      return media
    },
  }
  return {
    env,
    values,
    listeners,
    meta,
    change(dark) {
      media.matches = dark
      listeners.forEach((fn) => fn())
    },
  }
}

for (const mode of ['light', 'dark', 'system']) {
  test(`${mode} restores the chosen mode, applies data-theme, native scheme and browser chrome`, () => {
    const state = browser({ stored: mode, dark: true })
    const theme = createThemePreferences(state.env)
    theme.initialize()
    const expected = mode === 'light' ? 'light' : 'dark'
    assert.equal(theme.mode.value, mode)
    assert.equal(theme.resolved.value, expected)
    assert.equal(state.env.document.documentElement.dataset.theme, expected)
    assert.equal(state.env.document.documentElement.style.colorScheme, expected)
    assert.equal(state.meta.content, expected === 'dark' ? '#15191f' : '#f7f8fa')
    theme.dispose()
    assert.equal(state.listeners.size, 0)
  })
}

test('system is the unsaved default and follows OS changes; manual modes ignore them', () => {
  const state = browser()
  const theme = createThemePreferences(state.env)
  theme.initialize()
  assert.equal(theme.mode.value, 'system')
  assert.equal(theme.resolved.value, 'light')
  assert.equal(state.values.size, 0)
  state.change(true)
  assert.equal(theme.resolved.value, 'dark')
  theme.select('light')
  state.change(false)
  state.change(true)
  assert.equal(theme.resolved.value, 'light')
  theme.select('system')
  assert.equal(theme.resolved.value, 'dark')
  state.change(false)
  assert.equal(theme.resolved.value, 'light')
  theme.initialize()
  assert.equal(state.listeners.size, 1, 'reinitialization must not accumulate listeners')
  theme.dispose()
})

test('selection persists across instances; invalid values never replace a valid preference', () => {
  const state = browser()
  const theme = createThemePreferences(state.env)
  theme.initialize()
  for (const mode of ['light', 'dark', 'system']) {
    assert.equal(theme.select(mode), true)
    assert.equal(state.values.get(THEME_KEY), mode)
    const reloaded = createThemePreferences(state.env)
    reloaded.initialize()
    assert.equal(reloaded.mode.value, mode)
    reloaded.dispose()
  }
  assert.equal(theme.select('sepia'), false)
  assert.equal(state.values.get(THEME_KEY), 'system')
  theme.dispose()
})

test('corrupt, unreadable, missing or unwritable storage never prevents in-memory selection', () => {
  for (const options of [
    { stored: '{broken' },
    { stored: 'null' },
    { failRead: true },
    { failWrite: true },
  ]) {
    const state = browser({ ...options, dark: true })
    const theme = createThemePreferences(state.env)
    theme.initialize()
    assert.equal(theme.mode.value, 'system')
    assert.equal(theme.resolved.value, 'dark')
    assert.equal(theme.select('light'), true)
    assert.equal(theme.resolved.value, 'light')
    theme.dispose()
  }
  const state = browser()
  Object.defineProperty(state.env, 'localStorage', {
    get() {
      throw new Error('SecurityError')
    },
  })
  const theme = createThemePreferences(state.env)
  theme.initialize()
  assert.equal(theme.select('dark'), true)
  assert.equal(theme.resolved.value, 'dark')
  theme.dispose()
  const unsupported = createThemePreferences({ document: state.env.document })
  unsupported.initialize()
  assert.equal(unsupported.resolved.value, 'light')
  unsupported.select('dark')
  assert.equal(unsupported.resolved.value, 'dark')
})

test('theme selection never changes the project snapshot, tasks, drafts or existing storage', async () => {
  const state = browser()
  const previous = Object.getOwnPropertyDescriptor(globalThis, 'localStorage')
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: state.env.localStorage,
  })
  try {
    setActivePinia(createPinia())
    const store = useProjectStore()
    store.loadFromStorage()
    await nextTick()
    const project = JSON.stringify(store.$state)
    const saved = state.values.get(STORAGE_KEY)
    assert.ok(saved)
    const theme = createThemePreferences(state.env)
    theme.initialize()
    for (const mode of ['light', 'dark', 'system']) theme.select(mode)
    state.change(true)
    await nextTick()
    assert.equal(JSON.stringify(store.$state), project)
    assert.equal(state.values.get(STORAGE_KEY), saved)
    assert.deepEqual([...state.values.keys()].sort(), [STORAGE_KEY, THEME_KEY].sort())
    theme.dispose()
  } finally {
    if (previous) Object.defineProperty(globalThis, 'localStorage', previous)
    else delete globalThis.localStorage
  }
})

test('head bootstrap applies the theme before splash and Vue, even when storage access throws', () => {
  const html = readFileSync('index.html', 'utf8')
  const source = html.match(/<script id="theme-bootstrap">([\s\S]*?)<\/script>/)[1]
  assert.ok(html.indexOf('id="theme-bootstrap"') < html.indexOf('<body>'))
  for (const mode of [undefined, 'light', 'dark', 'system', '{broken']) {
    for (const dark of [false, true]) {
      const state = browser({ stored: mode, dark })
      vm.runInNewContext(source, state.env)
      const expected = ['light', 'dark'].includes(mode) ? mode : dark ? 'dark' : 'light'
      assert.equal(state.env.document.documentElement.dataset.theme, expected)
      assert.equal(state.env.document.documentElement.style.colorScheme, expected)
      assert.equal(state.meta.content, expected === 'dark' ? '#15191f' : '#f7f8fa')
      state.change(!dark)
      assert.equal(
        state.env.document.documentElement.dataset.theme,
        ['light', 'dark'].includes(mode) ? mode : dark ? 'light' : 'dark',
      )
      state.change(dark)
      const theme = createThemePreferences(state.env)
      theme.initialize()
      assert.equal(theme.resolved.value, expected, 'bootstrap and Vue must agree')
      theme.dispose()
    }
  }
  const state = browser({ dark: true })
  Object.defineProperty(state.env, 'localStorage', {
    get() {
      throw new Error('SecurityError')
    },
  })
  vm.runInNewContext(source, state.env)
  assert.equal(state.env.document.documentElement.dataset.theme, 'dark')
  assert.equal(state.meta.content, '#15191f')
})

test('Vue takes over the system theme listener without leaving the head listener active', () => {
  const state = browser({ dark: true })
  const source = readFileSync('index.html', 'utf8').match(
    /<script id="theme-bootstrap">([\s\S]*?)<\/script>/,
  )[1]
  vm.runInNewContext(source, state.env)
  assert.equal(state.listeners.size, 1)
  const theme = createThemePreferences(state.env)
  theme.initialize()
  assert.equal(state.listeners.size, 1)
  theme.select('light')
  state.change(false)
  state.change(true)
  assert.equal(state.env.document.documentElement.dataset.theme, 'light')
  theme.dispose()
  assert.equal(state.listeners.size, 0)
})
