import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '../api/client.js'
import { t } from '../i18n/index.js'
import { svgRaster } from '../services/branding.js'
const sections = ['visual', 'business', 'contact', 'export', 'email']
const copy = (value) => JSON.parse(JSON.stringify(value))
export const useWorkspaceBrandingStore = defineStore('workspaceBranding', () => {
  const settings = ref(null),
    draft = ref(null),
    workspaceId = ref(''),
    loading = ref(false),
    busy = ref(''),
    error = ref(null),
    upload = ref(null),
    pendingAsset = ref(null)
  const hasChanges = computed(
    () =>
      !!draft.value &&
      sections.some(
        (section) =>
          JSON.stringify(draft.value[section]) !== JSON.stringify(settings.value[section]),
      ),
  )
  const dirty = computed(() => hasChanges.value || !!pendingAsset.value)
  let revision = 0,
    controller
  const root = () => `/api/admin/workspaces/${workspaceId.value}/branding`
  function clear() {
    revision++
    controller?.abort()
    workspaceId.value = ''
    settings.value = null
    draft.value = null
    loading.value = false
    busy.value = ''
    error.value = null
    upload.value = null
    pendingAsset.value = null
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
  async function save() {
    if (busy.value || pendingAsset.value || !hasChanges.value) return false
    const current = revision,
      path = root(),
      snapshot = copy(draft.value)
    const changed = sections.filter(
      (key) => JSON.stringify(snapshot[key]) !== JSON.stringify(settings.value[key]),
    )
    const values = Object.fromEntries(changed.map((key) => [key, snapshot[key]]))
    const versions = Object.fromEntries(changed.map((key) => [key, settings.value.versions[key]]))
    controller = new AbortController()
    busy.value = 'save'
    error.value = null
    try {
      const result = await api.request(path, {
        method: 'PATCH',
        body: { sections: values, versions },
        signal: controller.signal,
      })
      if (current !== revision) return false
      settings.value = result.data
      for (const key of sections) {
        if (JSON.stringify(draft.value[key]) === JSON.stringify(snapshot[key]))
          draft.value[key] = copy(result.data[key])
      }
      return true
    } catch (cause) {
      if (current === revision) error.value = cause
      return false
    } finally {
      if (current === revision) busy.value = ''
    }
  }
  async function rebase() {
    if (busy.value) return false
    const current = revision,
      baseline = copy(settings.value)
    busy.value = 'reload'
    try {
      const result = await api.request(root())
      if (current !== revision) return false
      for (const key of sections) {
        const edits = Object.fromEntries(
          Object.entries(draft.value[key]).filter(
            ([field, value]) => JSON.stringify(value) !== JSON.stringify(baseline[key][field]),
          ),
        )
        draft.value[key] = { ...copy(result.data[key]), ...edits }
      }
      settings.value = result.data
      draft.value.assets = copy(result.data.assets)
      draft.value.assetInfo = copy(result.data.assetInfo || {})
      error.value = null
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
    const requestController = new AbortController()
    controller = requestController
    busy.value = kind
    error.value = null
    if (file) pendingAsset.value = { kind, file }
    upload.value = file ? { kind, phase: 'processing', percent: null } : null
    const transfer = (phase) => ({
      signal: requestController.signal,
      onUploadStart: () => {
        if (current === revision) upload.value = { kind, phase, percent: null }
      },
      onUploadProgress: ({ loaded, total }) => {
        if (current === revision && upload.value)
          upload.value.percent = total ? Math.min(100, Math.floor((loaded * 100) / total)) : null
      },
      onUploadComplete: () => {
        if (current === revision) upload.value = { kind, phase: 'processing', percent: null }
      },
      onSecurityRequired: () => {
        if (current === revision) upload.value = { kind, phase: 'confirming', percent: null }
      },
    })
    try {
      let body
      if (file) {
        if (file.size > 2 * 1024 * 1024) throw { messageKey: 'branding.invalid_image' }
        body = new FormData()
        body.append('file', file)
        if (file.type === 'image/svg+xml' || file.name.toLowerCase().endsWith('.svg')) {
          const result = await api.request(path + '/sanitize-svg', {
            method: 'POST',
            body,
            ...transfer('sanitizing'),
          })
          if (current !== revision) return false
          body = new FormData()
          body.append('file', new File([result.svg], 'logo.svg', { type: 'image/svg+xml' }))
          body.append('raster', await svgRaster(result.svg))
        }
      }
      if (current !== revision) return false
      const result = await api.request(path + '/assets/' + kind, {
        method: file ? 'POST' : 'DELETE',
        body,
        ...(file ? transfer('uploading') : { signal: requestController.signal }),
      })
      if (current !== revision) return false
      pendingAsset.value = null
      settings.value.assets = result.data.assets
      draft.value.assets = copy(result.data.assets)
      settings.value.assetInfo = result.data.assetInfo
      draft.value.assetInfo = copy(result.data.assetInfo || {})
      return true
    } catch (cause) {
      if (current === revision) error.value = cause
      return false
    } finally {
      if (current === revision) {
        busy.value = ''
        upload.value = null
      }
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
      pendingAsset.value = null
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
    hasChanges,
    pendingAsset,
    discardAsset: () => {
      pendingAsset.value = null
      error.value = null
    },
    retryAsset: () =>
      pendingAsset.value ? asset(pendingAsset.value.kind, pendingAsset.value.file) : false,
    clear,
    load,
    save,
    rebase,
    upload,
    asset,
    reset,
    confirmLeave,
  }
})
