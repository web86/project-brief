import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../api/client.js'
export const useProjectRequestsStore = defineStore('projectRequests', () => {
  const items = ref([]),
    selected = ref(null),
    meta = ref(null),
    error = ref(null)
  const loading = ref(false),
    detailLoading = ref(false),
    busy = ref(false)
  const status = ref('pending'),
    search = ref(''),
    sort = ref('desc'),
    page = ref(1)
  let workspace,
    revision = 0,
    listRevision = 0,
    detailRevision = 0
  const root = '/api/admin/project-requests'
  const keys = new Map()
  function clear() {
    revision++
    listRevision++
    detailRevision++
    workspace = null
    items.value = []
    selected.value = null
    meta.value = null
    error.value = null
    loading.value = false
    detailLoading.value = false
    busy.value = false
    status.value = 'pending'
    search.value = ''
    sort.value = 'desc'
    page.value = 1
    keys.clear()
  }
  async function load(workspaceId = workspace, request = api.request) {
    if (!workspaceId) return
    if (workspace && workspaceId !== workspace) clear()
    workspace = workspaceId
    const current = revision,
      list = ++listRevision
    loading.value = true
    error.value = null
    try {
      const query = new URLSearchParams({
        status: status.value,
        search: search.value,
        sort: sort.value,
        page: page.value,
      })
      const result = await request(root + '?' + query)
      if (current !== revision || list !== listRevision) return
      items.value = result.data
      meta.value = result.meta
      return result
    } catch (cause) {
      if (current === revision && list === listRevision) error.value = cause
    } finally {
      if (current === revision && list === listRevision) loading.value = false
    }
  }
  async function open(id, request = api.request, preserve = false) {
    const current = revision,
      detail = ++detailRevision
    if (!preserve) selected.value = null
    detailLoading.value = true
    error.value = null
    try {
      const result = await request(root + '/' + id)
      if (current === revision && detail === detailRevision) selected.value = result.data
    } catch (cause) {
      if (current === revision && detail === detailRevision) error.value = cause
    } finally {
      if (current === revision && detail === detailRevision) detailLoading.value = false
    }
  }
  function close() {
    if (!busy.value) {
      detailRevision++
      selected.value = null
      detailLoading.value = false
    }
  }
  async function decide(action, body, request = api.request) {
    if (busy.value || !selected.value || selected.value.status !== 'pending') return false
    const current = revision,
      id = selected.value.id
    const fingerprint = JSON.stringify([workspace, id, action, body])
    if (!keys.has(fingerprint)) keys.set(fingerprint, crypto.randomUUID())
    busy.value = true
    error.value = null
    try {
      const result = await request(root + '/' + id + '/' + action, {
        method: 'POST',
        body,
        headers: { 'Idempotency-Key': keys.get(fingerprint) },
      })
      if (current !== revision) return false
      selected.value = result.data
      await load(workspace, request)
      return true
    } catch (cause) {
      if (current === revision) {
        error.value = cause
        if (cause.status === 409) {
          const message = cause
          await open(id, request)
          if (current === revision) error.value = message
          await load(workspace, request)
          if (current === revision) error.value = message
        }
      }
      return false
    } finally {
      if (current === revision) busy.value = false
    }
  }
  async function refresh(request = api.request) {
    if (!workspace || busy.value) return
    const id = selected.value?.id
    await Promise.all([load(workspace, request), id ? open(id, request, true) : Promise.resolve()])
    if (error.value) throw error.value
  }
  return {
    items,
    selected,
    meta,
    error,
    loading,
    detailLoading,
    busy,
    status,
    search,
    sort,
    page,
    clear,
    load,
    open,
    close,
    decide,
    refresh,
  }
})
