import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../api/client.js'
export const listQuery = (query) =>
  Object.fromEntries(
    Object.entries({
      q: String(query.q || '').slice(0, 150),
      status: ['active', 'inactive'].includes(query.status) ? query.status : '',
      client: String(query.client || '').slice(0, 150),
      sort: ['created', 'name'].includes(query.sort) ? query.sort : '',
      page: Math.max(1, Math.min(100000, parseInt(query.page) || 1)),
    }).filter(([key, value]) => value !== '' && (key !== 'page' || value !== 1)),
  )
export const useProjectListingStore = defineStore('projectListing', () => {
  const projects = ref([]),
    meta = ref({ current_page: 1, last_page: 1, total: 0 }),
    loading = ref(false),
    error = ref(null)
  let revision = 0
  function clear() {
    revision++
    projects.value = []
    meta.value = { current_page: 1, last_page: 1, total: 0 }
    loading.value = false
    error.value = null
  }
  async function load(workspaceId, query) {
    const current = ++revision
    loading.value = true
    error.value = null
    try {
      const qs = new URLSearchParams(listQuery(query)).toString()
      const result = await api.request('/api/admin/projects' + (qs ? '?' + qs : ''))
      if (current !== revision) return false
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
  return { projects, meta, loading, error, load, clear }
})
