import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '../api/client.js'
import { t } from '../i18n/index.js'
import { svgRaster } from '../services/branding.js'
const copy = (value) => JSON.parse(JSON.stringify(value))
export const useWorkspaceBrandingStore = defineStore('workspaceBranding', () => {
  const settings = ref(null),
    draft = ref(null),
    workspaceId = ref(''),
    loading = ref(false),
    busy = ref(''),
    error = ref(null)
  const dirty = computed(
    () => !!draft.value && JSON.stringify(draft.value) !== JSON.stringify(settings.value),
  )
  let revision = 0
  const root = () => `/api/admin/workspaces/${workspaceId.value}/branding`
  function clear() {
    revision++
    workspaceId.value = ''
    settings.value = null
    draft.value = null
    loading.value = false
    busy.value = ''
    error.value = null
  }
  function confirmLeave() {
    if (!dirty.value || window.confirm(t('branding.unsaved'))) {
      if (settings.value) draft.value = copy(settings.value)
      return true
    }
    return false
  }
  async function load(id) {
    clear()
    workspaceId.value = id
    const current = revision
    loading.value = true
    try {
      const result = await api.request(root())
      if (current === revision) {
        settings.value = result.data
        draft.value = copy(result.data)
      }
    } catch (cause) {
      if (current === revision) error.value = cause
    } finally {
      if (current === revision) loading.value = false
    }
  }
  async function save(section) {
    if (busy.value) return false
    const current = revision,
      snapshot = JSON.stringify(draft.value[section])
    busy.value = section
    error.value = null
    try {
      const result = await api.request(root(), {
        method: 'PATCH',
        body: { section, values: copy(draft.value[section]) },
      })
      if (current !== revision) return false
      settings.value = result.data
      if (JSON.stringify(draft.value[section]) === snapshot)
        draft.value[section] = copy(result.data[section])
      return true
    } catch (cause) {
      if (current === revision) error.value = cause
      return false
    } finally {
      if (current === revision) busy.value = ''
    }
  }
  async function asset(kind, file) {
    if (busy.value) return false
    const current = revision,
      path = root()
    busy.value = kind
    error.value = null
    try {
      let body
      if (file) {
        if (file.size > 2 * 1024 * 1024) throw { messageKey: 'branding.invalid_image' }
        body = new FormData()
        body.append('file', file)
        if (file.type === 'image/svg+xml' || file.name.toLowerCase().endsWith('.svg')) {
          const result = await api.request(path + '/sanitize-svg', { method: 'POST', body })
          if (current !== revision) return false
          body = new FormData()
          body.append('file', new File([result.svg], 'logo.svg', { type: 'image/svg+xml' }))
          body.append('raster', await svgRaster(result.svg))
        }
      }
      const result = await api.request(path + '/assets/' + kind, {
        method: file ? 'POST' : 'DELETE',
        body,
      })
      if (current !== revision) return false
      settings.value.assets = result.data.assets
      draft.value.assets = copy(result.data.assets)
      return true
    } catch (cause) {
      if (current === revision) error.value = cause
      return false
    } finally {
      if (current === revision) busy.value = ''
    }
  }
  async function reset() {
    if (busy.value || !window.confirm(t('branding.resetConfirm'))) return false
    const current = revision
    busy.value = 'reset'
    error.value = null
    try {
      const result = await api.request(root() + '/reset', { method: 'POST' })
      if (current !== revision) return false
      const business = copy(draft.value.business),
        contact = copy(draft.value.contact)
      settings.value = result.data
      draft.value = { ...copy(result.data), business, contact }
      return true
    } catch (cause) {
      if (current === revision) error.value = cause
      return false
    } finally {
      if (current === revision) busy.value = ''
    }
  }
  return {
    settings,
    draft,
    workspaceId,
    loading,
    busy,
    error,
    dirty,
    clear,
    load,
    save,
    asset,
    reset,
    confirmLeave,
  }
})
