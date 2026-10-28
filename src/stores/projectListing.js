import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../api/client.js'
export const listQuery = (query) =>
  Object.fromEntries(
    Object.entries({
      q: String(query.q || '')
        .trim()
        .slice(0, 150),
      status: ['active', 'inactive'].includes(query.status) ? query.status : '',
      sort: ['created', 'name'].includes(query.sort) ? query.sort : '',
      page: Math.max(1, Math.min(100000, parseInt(query.page) || 1)),
    }).filter(([key, value]) => value !== '' && (key !== 'page' || value !== 1)),
  )
export const projectRequestQuery = (query) => {
  const result = listQuery(query)
  if ([...(result.q || '')].length < 3) delete result.q
  return result
}
export const useProjectListingStore = defineStore('projectListing', () => {
  const projects = ref([]),
    meta = ref({ current_page: 1, last_page: 1, total: 0 }),
    loading = ref(false),
    error = ref(null)
  let revision = 0,
    controller,
    loadedKey = ''
  function cancel() {
    revision++
    controller?.abort()
    loading.value = false
  }
  function clear() {
    cancel()
    loadedKey = ''
    projects.value = []
    meta.value = { current_page: 1, last_page: 1, total: 0 }
    loading.value = false
    error.value = null
  }
  async function load(workspaceId, query) {
    const qs = new URLSearchParams(projectRequestQuery(query)).toString()
    const key = workspaceId + ':' + qs
    if (key === loadedKey && !error.value) return true
    cancel()
    controller = new AbortController()
    const current = revision
    loading.value = true
    error.value = null
    try {
      const result = await api.request('/api/admin/projects' + (qs ? '?' + qs : ''), {
        signal: controller.signal,
      })
      if (current !== revision) return false
      loadedKey = key
      projects.value = result.data
      meta.value = result.meta || { current_page: 1, last_page: 1, total: result.data.length }
      return true
    } catch (cause) {
      if (current === revision) error.value = cause
      return false
    } finally {
      if (current === revision) loading.value = false
    }
  }
  function refresh() {
    loadedKey = ''
  }
  return { projects, meta, loading, error, load, clear, cancel, refresh }
})
