import { defineStore } from 'pinia'
import { ref } from 'vue'
import { canOpenProjectSection } from '../services/projectPermissions.js'
import { api } from '../api/client.js'

export const useProjectManagementStore = defineStore('projectManagement', () => {
  const project = ref(null),
    sections = ref([]),
    clients = ref([]),
    notifications = ref(null),
    loading = ref(false)
  let revision = 0
  const root = () => `/api/admin/projects/${project.value.id}`
  function clear() {
    revision++
    project.value = null
    sections.value = []
    clients.value = []
    notifications.value = null
    loading.value = false
  }
  function adopt(data) {
    project.value = data
  }
  async function load(id, section = 'settings', { defer = false, isOwner = false } = {}) {
    const previous = project.value?.id === id ? project.value : null
    const current = ++revision
    loading.value = true
    try {
      const details = await api.request(`/api/admin/projects/${id}?summary=1`)
      if (current !== revision) return false
      if (!canOpenProjectSection(details.data, section, isOwner)) {
        const error = new Error('Project permission required')
        error.status = 403
        error.code = 'project_permission_denied'
        throw error
      }
      const resource = ['clients', 'structure', 'notifications'].includes(section)
        ? await api.request(
            `/api/admin/projects/${id}/${section === 'structure' ? 'sections' : section}`,
          )
        : null
      if (current !== revision) return false
      const commit = () => {
        if (current !== revision) return false
        if (!previous) clearData()
        project.value = details.data
        if (section === 'clients') clients.value = resource.data
        if (section === 'structure') sections.value = resource.data
        if (section === 'notifications') notifications.value = resource
        return true
      }
      return defer ? commit : commit()
    } finally {
      if (current === revision) loading.value = false
    }
  }
  function clearData() {
    project.value = null
    sections.value = []
    clients.value = []
    notifications.value = null
  }
  async function saveProject(body) {
    const current = revision
    const result = await api.request(root() + '?summary=1', { method: 'PATCH', body })
    if (current === revision) project.value = result.data
    return result.data
  }
  async function mutateSection(id, method, body) {
    const current = revision
    const result = await api.request(`${root()}/sections${id ? `/${id}` : ''}`, { method, body })
    if (current === revision) sections.value = result.data
  }
  async function mutateClient(id, method, body) {
    const current = revision
    const result = await api.request(`${root()}/clients${id ? `/${id}` : ''}`, { method, body })
    if (current === revision) clients.value = result.data
    return result
  }
  async function inviteClient(id) {
    const current = revision
    const result = await api.request(`${root()}/clients/${id}/invitation`, { method: 'POST' })
    if (current === revision) clients.value = result.data
    return result
  }
  async function createAccessLink(clientId) {
    const current = revision,
      path = root()
    const result = await api.request(`${path}/clients/${clientId}/access-links`, { method: 'POST' })
    const people = await api.request(`${path}/clients`)
    if (current !== revision) return null
    clients.value = people.data
    return result.url
  }
  async function revokeAccess(clientId) {
    const current = revision,
      path = root()
    const client = clients.value.find((item) => item.id === clientId)
    for (const token of client.accessLinks.filter((link) => link.active))
      await api.request(`${path}/clients/${clientId}/access-links/${token.id}`, {
        method: 'DELETE',
      })
    const people = await api.request(`${path}/clients`)
    if (current === revision) clients.value = people.data
  }
  return {
    project,
    sections,
    clients,
    notifications,
    loading,
    load,
    clear,
    adopt,
    saveProject,
    mutateSection,
    mutateClient,
    inviteClient,
    createAccessLink,
    revokeAccess,
  }
})
