import { t } from '../i18n/index.js'

// sessionStorage survives navigation/reload without storing any authorization data.
const suggestionKey = 'projectbrief.pushSuggestionSeen'
let suggestedInMemory = false
function sessionStorage() {
  try {
    return globalThis.sessionStorage
  } catch {
    return null
  }
}
export function createPushEnrollment({ device, toast, storage = sessionStorage(), update }) {
  let active = true
  let suggestion = null
  let busy = false
  function suggested() {
    try {
      return storage ? storage.getItem(suggestionKey) === 'true' : suggestedInMemory
    } catch {
      return suggestedInMemory
    }
  }
  async function run(action, feedback = null) {
    if (!active || busy) return
    busy = true
    update({ busy: true, error: false })
    try {
      const result = await action()
      if (!active || !result) return
      update({ state: result.state })
      if (feedback && ['enabled', 'disabled'].includes(result.state))
        toast.success(() => t(feedback))
      if (result.state === 'ownership') toast.warning(() => t('pwa.pushOwnership'))
      return result
    } catch {
      if (active) {
        update({ error: true })
        toast.error(() => t('notifications.deviceError'))
      }
    } finally {
      busy = false
      if (active) update({ busy: false })
    }
  }
  async function start() {
    const result = await run(device.enroll)
    if (!active || result?.state !== 'default' || suggested()) return
    suggestedInMemory = true
    try {
      storage?.setItem(suggestionKey, 'true')
    } catch {}
    suggestion = toast.info(() => t('pwa.pushSuggestion'), {
      duration: 15000,
      actionLabel: () => t('ui.enable'),
      action: enable,
    })
  }
  function enable() {
    if (suggestion) toast.dismiss(suggestion)
    return run(device.enable, 'pwa.pushEnabled')
  }
  function disable() {
    if (suggestion) toast.dismiss(suggestion)
    return run(device.disable, 'pwa.pushDisabled')
  }
  return {
    start,
    enable,
    disable,
    retry: start,
    dispose() {
      active = false
      device.dispose()
      if (suggestion) toast.dismiss(suggestion)
    },
  }
}
