import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, commentBody } from '../api/client.js'

export const useTeamChatStore = defineStore('team-chat', () => {
  const projectId = ref(''),
    messages = ref([]),
    before = ref(null),
    unread = ref(0),
    draft = ref('')
  let revision = 0,
    loading = null
  const root = (id) => `/api/admin/projects/${id}/team-chat`
  function clear() {
    revision++
    projectId.value = ''
    messages.value = []
    before.value = null
    unread.value = 0
    draft.value = ''
    loading = null
  }
  async function load(id, older = false) {
    if (loading && projectId.value === id && !older) return loading
    if (projectId.value !== id) {
      clear()
      projectId.value = id
    }
    const current = revision
    const operation = (async () => {
      const result = await api.request(
        root(id) + (older && before.value ? `?before=${before.value}` : ''),
      )
      if (current !== revision) return false
      const rows = new Map([...messages.value, ...result.data].map((row) => [row.id, row]))
      messages.value = [...rows.values()].sort((a, b) => a.sequence - b.sequence)
      if (older || !before.value) before.value = result.before
      unread.value = result.unread
      return true
    })()
      .catch((error) => {
        if (current === revision && [401, 403, 404].includes(error.status)) clear()
        throw error
      })
      .finally(() => {
        if (loading === operation) loading = null
      })
    loading = operation
    return operation
  }
  async function send(text, files, submissionId) {
    const id = projectId.value,
      current = revision
    const result = await api.request(root(id), {
      method: 'POST',
      body: commentBody(text, files, submissionId),
    })
    if (current !== revision) return false
    if (!messages.value.some((row) => row.id === result.data.id)) messages.value.push(result.data)
    draft.value = ''
    return true
  }
  async function read() {
    const through = messages.value.at(-1)?.sequence,
      current = revision
    if (!through || !unread.value) return
    const result = await api.request(root(projectId.value) + '/read', {
      method: 'POST',
      body: { through },
    })
    if (current === revision) unread.value = result.unread
  }
  return { projectId, messages, before, unread, draft, clear, load, send, read }
})
