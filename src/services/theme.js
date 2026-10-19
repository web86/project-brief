import { readonly, ref } from 'vue'

export const THEME_KEY = 'projectbrief.theme'
export const THEME_MODES = ['light', 'dark', 'system']

export function createThemePreferences(env = globalThis) {
  const mode = ref('system')
  const resolved = ref('light')
  let media

  function apply() {
    resolved.value = mode.value === 'system' ? (media?.matches ? 'dark' : 'light') : mode.value
    const document = env.document
    if (!document) return
    document.documentElement.dataset.theme = resolved.value
    document.documentElement.style.colorScheme = resolved.value
    document
      .querySelector('meta[name="theme-color"]')
      ?.setAttribute('content', resolved.value === 'dark' ? '#15191f' : '#f7f8fa')
  }

  function systemChanged() {
    if (mode.value === 'system') apply()
  }

  function dispose() {
    media?.removeEventListener('change', systemChanged)
    media = undefined
  }

  function initialize() {
    dispose()
    let stored
    try {
      stored = env.localStorage?.getItem(THEME_KEY)
    } catch {}
    mode.value = THEME_MODES.includes(stored) ? stored : 'system'
    media = env.matchMedia?.('(prefers-color-scheme: dark)')
    media?.addEventListener('change', systemChanged)
    apply()
  }

  function select(value) {
    if (!THEME_MODES.includes(value)) return false
    mode.value = value
    apply()
    try {
      env.localStorage?.setItem(THEME_KEY, value)
    } catch {}
    return true
  }

  return { mode: readonly(mode), resolved: readonly(resolved), initialize, select, dispose }
}

export const theme = createThemePreferences()
