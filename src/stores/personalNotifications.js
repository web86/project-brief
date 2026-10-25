import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '../api/client.js'

export function telegramDismissalKey(persona, id) {
  return persona && id != null
    ? `projectbrief.telegram-prompt.dismissed.v1:${persona}:${encodeURIComponent(id)}`
    : null
}

export const usePersonalNotificationsStore = defineStore('personalNotifications', () => {
  const identity = ref(null),
    settings = ref(null),
    telegram = ref(null),
    dismissed = ref(false),
    savingPreferences = ref({})
  const root = computed(() => (identity.value ? `/api/${identity.value.persona}` : null))
  let revision = 0,
    settingsRevision = 0,
    settingsPending = null,
    telegramPending = null
  let preferenceQueue = Promise.resolve(),
    preferencePending = {}
  function setIdentity(persona, id) {
    const key = telegramDismissalKey(persona, id)
    if (key === identity.value?.key || (!key && !identity.value)) return
    revision++
    preferenceQueue = Promise.resolve()
    preferencePending = {}
    savingPreferences.value = {}
    identity.value = key ? { persona, id, key } : null
    settings.value = null
    telegram.value = null
    settingsPending = telegramPending = null
    dismissed.value = false
    try {
      dismissed.value = !!key && globalThis.localStorage?.getItem(key) === 'true'
    } catch {}
  }
  function dismiss() {
    if (!identity.value) return
    dismissed.value = true
    try {
      globalThis.localStorage?.setItem(identity.value.key, 'true')
    } catch {}
  }
  function adoptSettings(result, key = identity.value?.key) {
    if (key && key === identity.value?.key) {
      settingsRevision++
      settings.value = result
    }
  }
  function savePreference(channel, enabled, request = api.request) {
    if (
      !['email', 'push', 'telegram'].includes(channel) ||
      typeof enabled !== 'boolean' ||
      !identity.value ||
      !settings.value
    )
      return Promise.resolve(false)
    if (preferencePending[channel]) return preferencePending[channel]
    if (settings.value.preferences[channel] === enabled) return Promise.resolve(false)
    const current = revision,
      key = identity.value.key,
      path = root.value + '/notifications'
    savingPreferences.value = { ...savingPreferences.value, [channel]: true }
    const operation = preferenceQueue
      .catch(() => {})
      .then(async () => {
        if (current !== revision) return false
        try {
          const result = await request(path, {
            method: 'PATCH',
            body: { preferences: { ...settings.value.preferences, [channel]: enabled } },
          })
          if (current !== revision) return false
          adoptSettings(result, key)
          return true
        } catch (error) {
          if (current !== revision) return false
          throw error
        }
      })
      .finally(() => {
        if (current === revision && preferencePending[channel] === operation) {
          delete preferencePending[channel]
          const pending = { ...savingPreferences.value }
          delete pending[channel]
          savingPreferences.value = pending
        }
      })
    preferencePending[channel] = operation
    preferenceQueue = operation
    return operation
  }
  async function loadSettings(force = false, request = api.request) {
    if (!root.value) return null
    if (settingsPending) return settingsPending
    if (settings.value && !force) return settings.value
    const current = revision,
      currentSettings = settingsRevision,
      path = root.value + '/notifications'
    const operation = request(path)
      .then((result) => {
        if (current !== revision) return null
        if (currentSettings !== settingsRevision) return settings.value
        settings.value = result
        return result
      })
      .finally(() => {
        if (current === revision) settingsPending = null
      })
    settingsPending = operation
    return operation
  }
  async function loadTelegram(force = false, request = api.request) {
    if (!root.value) return null
    if (telegramPending) return telegramPending
    if (telegram.value && !force) return telegram.value
    const current = revision,
      path = root.value + '/telegram'
    const operation = request(path)
      .then((result) => {
        if (current !== revision) return null
        telegram.value = result
        return result
      })
      .catch((error) => {
        // An unknown or failed state must never advertise a channel as available.
        if (current === revision) telegram.value = null
        throw error
      })
      .finally(() => {
        if (current === revision) telegramPending = null
      })
    telegramPending = operation
    return operation
  }
  const needsTelegram = computed(
    () =>
      !!identity.value &&
      !dismissed.value &&
      telegram.value?.available === true &&
      typeof settings.value?.preferences?.telegram === 'boolean' &&
      (!telegram.value.connected || !settings.value.preferences.telegram),
  )
  return {
    identity,
    savingPreferences,
    savePreference,
    root,
    settings,
    telegram,
    dismissed,
    needsTelegram,
    setIdentity,
    dismiss,
    adoptSettings,
    loadSettings,
    loadTelegram,
  }
})
