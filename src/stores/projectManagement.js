import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../api/client.js'

export const useProjectManagementStore = defineStore('projectManagement', () => {
  const project = ref(null)
  const sections = ref([])
  const clients = ref([])
  const loading = ref(false)
  let revision = 0
  const root = () => `/api/admin/projects/${project.value.id}`
  async function load(id) {
    const current = ++revision
    project.value = null
    sections.value = []
    clients.value = []
    loading.value = true
    try {
      const [details, structure, people] = await Promise.all([
        api.request(`/api/admin/projects/${id}`),
        api.request(`/api/admin/projects/${id}/sections`),
        api.request(`/api/admin/projects/${id}/clients`),
      ])
      if (current !== revision) return
      project.value = details.data
      sections.value = structure.data
      clients.value = people.data
    } finally {
      if (current === revision) loading.value = false
    }
  }
  async function saveProject(body) {
    project.value = (await api.request(root(), { method: 'PATCH', body })).data
  }
  async function mutateSection(id, method, body) {
    sections.value = (
      await api.request(`${root()}/sections${id ? `/${id}` : ''}`, { method, body })
    ).data
  }
  async function mutateClient(id, method, body) {
    clients.value = (
      await api.request(`${root()}/clients${id ? `/${id}` : ''}`, { method, body })
    ).data
  }
  async function createAccessLink(clientId) {
    const result = await api.request(`${root()}/clients/${clientId}/access-links`, {
      method: 'POST',
    })
    clients.value = (await api.request(`${root()}/clients`)).data
    return result.url
  }
  async function revokeAccess(clientId) {
    const client = clients.value.find((item) => item.id === clientId)
    for (const token of client.accessLinks.filter((link) => link.active))
      await api.request(`${root()}/clients/${clientId}/access-links/${token.id}`, {
        method: 'DELETE',
      })
    clients.value = (await api.request(`${root()}/clients`)).data
  }
  return {
    project,
    sections,
    clients,
    loading,
    load,
    saveProject,
    mutateSection,
    mutateClient,
    createAccessLink,
    revokeAccess,
  }
})
