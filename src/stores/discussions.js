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
      unreadCounts = ref({ general: 0, tasks: 0 }),
      drafts = ref({})
    const next = ref({ tasks: null, general: null }),
      filter = ref('all'),
      targetWindows = ref({ general: false, tasks: false }),
      loadingMore = ref({})
    let revision = 0,
      identity = 0,
      identityKey = '',
      readQueue = Promise.resolve()
    let readVersions = new Map(),
      feedVersions = { general: 0, tasks: 0 },
      morePending = {},
      targetsPending = new Map(),
      targetRevision = 0
    const readVersion = (id) => readVersions.get(id) || 0
    const collection = (kind) => (kind === 'general' ? general : tasks)
    const root = (id) =>
      persona === 'admin'
        ? `/api/admin/projects/${id}/discussions`
        : `/api/client/project/${id}/discussions`
    function setIdentity(key) {
      if (identityKey === key) return
      clear()
      identityKey = key
    }
    function updateCount(id, result) {
      const count = result.unreadCount
      if (projectId.value === id) {
        unreadCount.value = count
        if (result.unreadCounts) unreadCounts.value = { ...result.unreadCounts }
      }
      if (persona === 'admin') useAdminNavigationStore().updateCommentCount(id, count)
      else {
        const project = useProjectStore().project
        if (project.id === id) project.unreadCommentCount = count
      }
    }
    async function load(id, { defer = false, selectedFilter = 'all', reuse = false } = {}) {
      if (reuse && projectId.value === id && filter.value === selectedFilter)
        return defer ? () => true : true
      const current = ++revision
      await readQueue.catch(() => {})
      if (current !== revision) return false
      const reads = readVersion(id)
      const [feed, messages] = await Promise.all(
        ['tasks', 'general'].map((kind) =>
          api.request(`${root(id)}?kind=${kind}&filter=${selectedFilter}`),
        ),
      )
      if (current !== revision) return false
      if (reads !== readVersion(id)) return load(id, { defer, selectedFilter })
      const commit = () => {
        if (current !== revision || reads !== readVersion(id)) return false
        projectId.value = id
        tasks.value = feed.data
        general.value = messages.data
        next.value = { tasks: feed.nextCursor, general: messages.nextCursor }
        targetWindows.value = { general: false, tasks: false }
        loadingMore.value = {}
        morePending = {}
        targetsPending = new Map()
        targetRevision++
        feedVersions = { general: 0, tasks: 0 }
        unreadCounts.value = { general: 0, tasks: 0 }
        filter.value = selectedFilter
        updateCount(id, messages)
        return true
      }
      return defer ? commit : commit()
    }
    function more(kind) {
      if (morePending[kind]) return morePending[kind]
      const id = projectId.value,
        current = revision,
        cursor = next.value[kind],
        version = feedVersions[kind]
      if (!cursor) return Promise.resolve(false)
      loadingMore.value = { ...loadingMore.value, [kind]: true }
      const operation = (async () => {
        await readQueue.catch(() => {})
        if (current !== revision || id !== projectId.value) return false
        const reads = readVersion(id)
        let result
        try {
          result = await api.request(
            `${root(id)}?kind=${kind}&filter=${filter.value}&cursor=${encodeURIComponent(cursor)}`,
          )
        } catch (error) {
          if (current !== revision || id !== projectId.value) return false
          throw error
        }
        if (
          current !== revision ||
          id !== projectId.value ||
          version !== feedVersions[kind] ||
          reads !== readVersion(id)
        )
          return false
        const items = collection(kind),
          ids = new Set(items.value.map((item) => item.id))
        items.value.push(...result.data.filter((item) => !ids.has(item.id)))
        next.value[kind] = result.nextCursor
        updateCount(id, result)
        return true
      })().finally(() => {
        if (current === revision && morePending[kind] === operation) {
          delete morePending[kind]
          loadingMore.value = { ...loadingMore.value, [kind]: false }
        }
      })
      morePending[kind] = operation
      return operation
    }
    function kindForComment(uuid) {
      return ['general', 'tasks'].find((kind) =>
        collection(kind).value.some((item) => item.id === uuid),
      )
    }
    function revealComment(uuid) {
      const found = kindForComment(uuid)
      if (found) {
        targetRevision++
        targetsPending = new Map()
        return Promise.resolve(found)
      }
      if (targetsPending.has(uuid)) return targetsPending.get(uuid)
      targetsPending = new Map()
      const id = projectId.value,
        current = revision,
        targetVersion = ++targetRevision
      const operation = (async () => {
        let result
        for (;;) {
          await readQueue.catch(() => {})
          if (current !== revision || id !== projectId.value || targetVersion !== targetRevision)
            return null
          const reads = readVersion(id)
          try {
            result = await api.request(`${root(id)}?comment=${encodeURIComponent(uuid)}`)
          } catch (error) {
            if (current !== revision) return null
            throw error
          }
          if (current !== revision || id !== projectId.value || targetVersion !== targetRevision)
            return null
          if (reads === readVersion(id)) break
        }
        if (!['general', 'tasks'].includes(result.kind)) return null
        feedVersions[result.kind]++
        collection(result.kind).value = result.data
        next.value[result.kind] = result.nextCursor
        targetWindows.value = { ...targetWindows.value, [result.kind]: true }
        updateCount(id, result)
        return result.kind
      })().finally(() => {
        if (current === revision && targetsPending.get(uuid) === operation)
          targetsPending.delete(uuid)
      })
      targetsPending.set(uuid, operation)
      return operation
    }
    async function latest(kind) {
      targetRevision++
      targetsPending = new Map()
      const id = projectId.value,
        current = revision,
        version = ++feedVersions[kind]
      await readQueue.catch(() => {})
      if (current !== revision) return false
      const reads = readVersion(id)
      let result
      try {
        result = await api.request(`${root(id)}?kind=${kind}&filter=all`)
      } catch (error) {
        if (current !== revision) return false
        throw error
      }
      if (
        current !== revision ||
        id !== projectId.value ||
        version !== feedVersions[kind] ||
        reads !== readVersion(id)
      )
        return false
      collection(kind).value = result.data
      next.value[kind] = result.nextCursor
      targetWindows.value = { ...targetWindows.value, [kind]: false }
      updateCount(id, result)
      return true
    }
    function mark(id, ids, all = false, kind = null) {
      const currentIdentity = identity
      const operation = readQueue
        .catch(() => {})
        .then(async () => {
          if (identity !== currentIdentity) return false
          let result
          try {
            result = await api.request(`${root(id)}/read`, {
              method: 'POST',
              body: { ...(all ? { all: true } : { ids }), ...(kind ? { kind } : {}) },
            })
          } catch (error) {
            if (identity !== currentIdentity) return false
            throw error
          }
          if (identity !== currentIdentity) return false
          readVersions.set(id, readVersion(id) + 1)
          updateCount(id, result)
          if (projectId.value === id) {
            for (const category of ['general', 'tasks']) {
              for (const item of collection(category).value)
                if ((all && (!kind || kind === category)) || (!all && ids.includes(item.id)))
                  item.unread = false
              if (filter.value === 'unread')
                collection(category).value = collection(category).value.filter(
                  (item) => item.unread,
                )
            }
          }
          return true
        })
      readQueue = operation
      return operation
    }
    async function send(text) {
      const id = projectId.value,
        current = revision,
        currentIdentity = identity
      let result
      try {
        result = await api.request(root(id), { method: 'POST', body: { text: text.trim() } })
      } catch (error) {
        if (currentIdentity !== identity) return false
        throw error
      }
      if (currentIdentity !== identity) return false
      if (drafts.value[id]?.trim() === text.trim()) delete drafts.value[id]
      if (
        id === projectId.value &&
        current === revision &&
        filter.value === 'all' &&
        !targetWindows.value.general
      )
        general.value.unshift(result.data)
      else if (id === projectId.value && targetWindows.value.general) await latest('general')
      return true
    }
    function clear() {
      revision++
      identity++
      readQueue = Promise.resolve()
      readVersions = new Map()
      morePending = {}
      targetsPending = new Map()
      loadingMore.value = {}
      drafts.value = {}
      projectId.value = ''
      tasks.value = []
      general.value = []
      unreadCount.value = 0
      unreadCounts.value = { general: 0, tasks: 0 }
      next.value = { tasks: null, general: null }
      targetWindows.value = { general: false, tasks: false }
    }
    return {
      setIdentity,
      projectId,
      tasks,
      general,
      unreadCount,
      unreadCounts,
      drafts,
      next,
      filter,
      targetWindows,
      loadingMore,
      kindForComment,
      revealComment,
      latest,
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
