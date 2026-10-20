import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../api/client.js'
import { useProjectStore } from './project.js'
import { useAdminNavigationStore } from './adminNavigation.js'

function createDiscussionsStore(name, persona) {
  return defineStore(name, () => {
    const projectId = ref(''),
      tasks = ref([]),
      general = ref([]),
      unreadCount = ref(0),
      drafts = ref({})
    const next = ref({ tasks: null, general: null }),
      filter = ref('all')
    let revision = 0,
      identity = 0
    let identityKey = ''
    function setIdentity(key) {
      if (identityKey === key) return
      clear()
      identityKey = key
    }
    let readQueue = Promise.resolve()
    const root = (id) =>
      persona === 'admin'
        ? `/api/admin/projects/${id}/discussions`
        : `/api/client/project/${id}/discussions`
    function updateCount(id, count) {
      if (projectId.value === id) unreadCount.value = count
      if (persona === 'admin') useAdminNavigationStore().updateCommentCount(id, count)
      else {
        const project = useProjectStore().project
        if (project.id === id) project.unreadCommentCount = count
      }
    }
    async function load(id, { defer = false, selectedFilter = 'all' } = {}) {
      const current = ++revision
      await readQueue.catch(() => {})
      if (current !== revision) return false
      const [feed, messages] = await Promise.all(
        ['tasks', 'general'].map((kind) =>
          api.request(`${root(id)}?kind=${kind}&filter=${selectedFilter}`),
        ),
      )
      if (current !== revision) return false
      const commit = () => {
        if (current !== revision) return false
        projectId.value = id
        tasks.value = feed.data
        general.value = messages.data
        next.value = { tasks: feed.nextCursor, general: messages.nextCursor }
        filter.value = selectedFilter
        updateCount(id, Math.max(feed.unreadCount, messages.unreadCount))
        return true
      }
      return defer ? commit : commit()
    }
    async function more(kind) {
      const id = projectId.value,
        current = revision,
        cursor = next.value[kind]
      if (!cursor) return
      const result = await api.request(
        `${root(id)}?kind=${kind}&filter=${filter.value}&cursor=${encodeURIComponent(cursor)}`,
      )
      if (current !== revision || id !== projectId.value) return
      const collection = kind === 'tasks' ? tasks : general
      const ids = new Set(collection.value.map((item) => item.id))
      collection.value.push(...result.data.filter((item) => !ids.has(item.id)))
      next.value[kind] = result.nextCursor
      updateCount(id, result.unreadCount)
    }
    function mark(id, ids, all = false) {
      const currentIdentity = identity
      const operation = readQueue
        .catch(() => {})
        .then(async () => {
          if (identity !== currentIdentity) return
          await markNow(id, ids, all, currentIdentity)
        })
      readQueue = operation
      return operation
    }
    async function markNow(id, ids, all, currentIdentity) {
      const result = await api.request(`${root(id)}/read`, {
        method: 'POST',
        body: all ? { all: true } : { ids },
      })
      if (identity !== currentIdentity) return
      updateCount(id, result.unreadCount)
      if (projectId.value === id) {
        for (const item of [...tasks.value, ...general.value])
          if (all || ids.includes(item.id)) item.unread = false
        if (filter.value === 'unread') {
          tasks.value = tasks.value.filter((item) => item.unread)
          general.value = general.value.filter((item) => item.unread)
        }
      }
    }
    async function send(text) {
      const id = projectId.value,
        current = revision,
        currentIdentity = identity
      const result = await api.request(root(id), { method: 'POST', body: { text: text.trim() } })
      if (currentIdentity !== identity) return false
      delete drafts.value[id]
      if (id === projectId.value && current === revision && filter.value === 'all')
        general.value.unshift(result.data)
      return true
    }
    function clear() {
      revision++
      identity++
      drafts.value = {}
      projectId.value = ''
      tasks.value = []
      general.value = []
      unreadCount.value = 0
      next.value = { tasks: null, general: null }
    }
    return {
      setIdentity,
      projectId,
      tasks,
      general,
      unreadCount,
      drafts,
      next,
      filter,
      load,
      more,
      mark,
      send,
      clear,
    }
  })
}
export const useDiscussionsStore = createDiscussionsStore('discussions', 'admin')
export const useClientDiscussionsStore = createDiscussionsStore('client-discussions', 'client')
