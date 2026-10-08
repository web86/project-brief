import { t } from '../i18n/index.js'

export function confirmDeveloperLeave(store, confirm = (message) => window.confirm(message)) {
  return !Object.keys(store.developerDrafts).length || confirm(t('developerEditor.leave'))
}

// Bound only while TaskView is mounted. beforeunload is registered only while dirty.
export function bindDeveloperEditor({ active, dirty, save, target = window }) {
  let protectedUnload = false
  function keydown(event) {
    if (
      event.key.toLowerCase() !== 's' ||
      !(event.metaKey || event.ctrlKey) ||
      event.altKey ||
      event.shiftKey ||
      !active() ||
      !dirty()
    )
      return
    event.preventDefault()
    if (!event.repeat) void save()
  }
  function beforeunload(event) {
    if (!active() || !dirty()) return
    event.preventDefault()
    event.returnValue = ''
  }
  function sync() {
    const protect = active() && dirty()
    if (protect === protectedUnload) return
    if (protect) target.addEventListener('beforeunload', beforeunload)
    else target.removeEventListener('beforeunload', beforeunload)
    protectedUnload = protect
  }
  target.addEventListener('keydown', keydown)
  return {
    sync,
    dispose() {
      target.removeEventListener('keydown', keydown)
      target.removeEventListener('beforeunload', beforeunload)
    },
  }
}
