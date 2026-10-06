import { t, applyClientLocale, setManualLocale, SUPPORTED_LOCALES } from '../i18n/index.js'
import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import { api, API_MODE, taskBody } from '../api/client.js'
import { canEditClientTask } from '../utils/clientDraft.js'
import { createDemoData } from '../data/demo.js'
import {
  FILTERS,
  getTaskLocation,
  PRIORITIES,
  STATUSES,
  STORAGE_KEY,
  STORAGE_VERSION,
} from '../constants/project.js'

import {
  canonicalOrder,
  sectionForTask,
  taskNumber,
  migrateLocalStructure,
  normalizePositions,
  swapPosition,
} from '../utils/structure.js'

const timestamp = () => new Date().toISOString()
const uuid = () => crypto.randomUUID()
const validDate = (value) => typeof value === 'string' && Number.isFinite(Date.parse(value))
const validNumber = (value) =>
  value === null || (typeof value === 'number' && Number.isFinite(value) && value >= 0)
const isText = (value) => typeof value === 'string'
const validAttachment = (file) =>
  file &&
  isText(file.id) &&
  isText(file.name) &&
  isText(file.type) &&
  typeof file.size === 'number' &&
  Number.isFinite(file.size) &&
  file.size >= 0 &&
  (file.preview === null ||
    (isText(file.preview) && /^data:image\/(jpeg|png|webp);base64,/.test(file.preview)))

// Validate the entire snapshot before replacing any reactive state.
export function isValidSnapshot(data) {
  if (!Array.isArray(data?.sections) || !Array.isArray(data?.tasks)) return false
  const canonical = data.sections.every((section) => section && typeof section === 'object')
  const legacy = data.sections.every(isText)
  if (!canonical && !legacy) return false
  if (
    canonical &&
    (new Set(data.sections.map((section) => section.id)).size !== data.sections.length ||
      new Set(data.sections.map((section) => section.position)).size !== data.sections.length ||
      new Set(
        data.tasks
          .filter((task) => task?.sectionId)
          .map((task) => `${task.sectionId}:${task.position}`),
      ).size !== data.tasks.filter((task) => task?.sectionId).length)
  )
    return false
  if (data.tasks.some((task) => !!task?.sectionId !== Number.isInteger(task?.position)))
    return false
  return (
    data?.version === STORAGE_VERSION &&
    data.project &&
    isText(data.project.id) &&
    isText(data.project.name) &&
    isText(data.project.website) &&
    isText(data.project.description) &&
    (data.project.currency === undefined || isText(data.project.currency)) &&
    Array.isArray(data.sections) &&
    data.sections.every(
      (section) =>
        isText(section) ||
        (section &&
          isText(section.id) &&
          isText(section.name) &&
          Number.isInteger(section.position) &&
          section.position > 0),
    ) &&
    ['client', 'developer'].includes(data.currentMode) &&
    Array.isArray(data.tasks) &&
    new Set(data.tasks.map((task) => task?.id)).size === data.tasks.length &&
    data.tasks.every(
      (task) =>
        task &&
        isText(task.id) &&
        isText(task.title) &&
        (task.section === undefined ||
          isText(task.section) ||
          (task.section && isText(task.section.name))) &&
        (task.sectionId === undefined ||
          (isText(task.sectionId) &&
            data.sections.some((section) => section.id === task.sectionId))) &&
        (task.position === undefined || (Number.isInteger(task.position) && task.position > 0)) &&
        (task.location === undefined || isText(task.location)) &&
        isText(getTaskLocation(task)) &&
        !!getTaskLocation(task).trim() &&
        isText(task.description) &&
        isText(task.expectedResult) &&
        isText(task.developerNotes) &&
        Object.hasOwn(STATUSES, task.status) &&
        Object.hasOwn(PRIORITIES, task.priority) &&
        typeof task.clientApproved === 'boolean' &&
        validNumber(task.estimateHours) &&
        validNumber(task.price) &&
        validDate(task.createdAt) &&
        validDate(task.updatedAt) &&
        Array.isArray(task.attachments) &&
        task.attachments.every(validAttachment) &&
        Array.isArray(task.comments) &&
        task.comments.every(
          (comment) =>
            comment &&
            isText(comment.id) &&
            ['client', 'developer'].includes(comment.author) &&
            isText(comment.text) &&
            validDate(comment.createdAt),
        ) &&
        Array.isArray(task.history) &&
        task.history.every(
          (event) =>
            event && isText(event.type) && isText(event.text) && validDate(event.createdAt),
        ),
    )
  )
}

export const useProjectStore = defineStore('project', () => {
  const demo = API_MODE
    ? {
        project: { id: '', name: '', website: '', description: '', currency: 'RUB' },
        sections: [],
        tasks: [],
        currentMode: 'client',
      }
    : createDemoData()
  const project = ref(demo.project)
  const sections = ref(demo.sections)
  const tasks = ref(demo.tasks)
  const currentMode = ref(demo.currentMode)
  const apiMode = API_MODE
  const admin = ref(null)
  const currentClient = ref(null)
  const apiError = ref('')
  const sessionLost = ref(false)
  const apiLoading = ref(false)
  const orderPending = ref(false)
  const developerDrafts = ref({})
  const savingDeveloper = ref(false)
  let developerTimer
  const writes = new Map()
  const storageError = ref('')
  const storageBlocked = ref(false)
  const initialized = ref(false)
  let loading = false

  const isDeveloper = computed(() => (API_MODE ? !!admin.value : currentMode.value === 'developer'))
  const projectPath = computed(() =>
    !API_MODE
      ? '/'
      : admin.value
        ? `/admin/projects/${project.value.id}/brief`
        : `/project/${project.value.id}`,
  )
  const newTaskPath = computed(() => (!API_MODE ? '/task/new' : `${projectPath.value}/task/new`))
  const taskPath = (id) => (!API_MODE ? `/task/${id}` : `${projectPath.value}/task/${id}`)
  const actorPath = () => `/api/${admin.value ? 'admin' : 'client'}`
  function reportError(error) {
    apiError.value = error
    if (error.status === 401) sessionLost.value = true
  }
  function replaceTask(task) {
    Object.assign(task, developerDrafts.value[task.id] || {})
    const index = tasks.value.findIndex((item) => item.id === task.id)
    if (index < 0) tasks.value.unshift(task)
    else tasks.value.splice(index, 1, task)
    return task
  }
  function serialize(id, operation) {
    const previous = writes.get(id) || Promise.resolve()
    const next = previous.catch(() => {}).then(operation)
    writes.set(id, next)
    next
      .finally(() => {
        if (writes.get(id) === next) writes.delete(id)
      })
      .catch(() => {})
    return next
  }
  async function mutateTask(id, suffix, method, body) {
    apiError.value = ''
    return serialize(id, async () => {
      try {
        const { data } = await api.request(`${actorPath()}/tasks/${id}${suffix}`, { method, body })
        replaceTask(data)
        return true
      } catch (error) {
        reportError(error)
        return false
      }
    })
  }
  async function loadApiProject(id, asAdmin) {
    apiLoading.value = true
    apiError.value = ''
    sessionLost.value = false
    try {
      if (asAdmin) {
        currentClient.value = null
        const result = await api.request('/api/admin/me')
        admin.value = result.user
      } else {
        admin.value = null
        currentClient.value = await api.request('/api/client/me')
        applyClientLocale(currentClient.value.preferredLocale)
        developerDrafts.value = {}
      }
      await flushDeveloperData()
      const [details, list] = await Promise.all([
        api.request(asAdmin ? `/api/admin/projects/${id}` : `/api/client/project/${id}`),
        api.request(asAdmin ? `/api/admin/projects/${id}/tasks` : '/api/client/tasks'),
      ])
      project.value = details.data
      sections.value = details.data.sections
      tasks.value = list.data.map((task) =>
        Object.assign(task, developerDrafts.value[task.id] || {}),
      )
      currentMode.value = asAdmin ? 'developer' : 'client'
      initialized.value = true
    } catch (error) {
      tasks.value = []
      reportError(error)
      throw error
    } finally {
      apiLoading.value = false
    }
  }
  async function flushDeveloperData() {
    clearTimeout(developerTimer)
    const entries = Object.entries(developerDrafts.value).map(([id, data]) => [id, { ...data }])
    if (!entries.length) return
    savingDeveloper.value = true
    apiError.value = ''
    await Promise.all(
      entries.map(([id, draft]) =>
        serialize(id, async () => {
          try {
            const { data } = await api.request(`/api/admin/tasks/${id}`, {
              method: 'PATCH',
              body: draft,
            })
            for (const [key, value] of Object.entries(draft))
              if (developerDrafts.value[id]?.[key] === value) delete developerDrafts.value[id][key]
            if (!Object.keys(developerDrafts.value[id] || {}).length)
              delete developerDrafts.value[id]
            replaceTask(data)
          } catch (error) {
            reportError(error)
          }
        }),
      ),
    )
    savingDeveloper.value = false
  }
  async function uploadAttachments(id, attachments) {
    const body = new FormData()
    for (const file of attachments) body.append('attachments[]', file.file, file.name)
    return mutateTask(id, '/attachments', 'POST', body)
  }
  const locationOptions = computed(() => [
    ...new Set([
      ...sections.value.map((section) => section.name),
      ...tasks.value.map(getTaskLocation),
    ]),
  ])
  const sectionOptions = computed(() => canonicalOrder(sections.value))
  const numberForTask = (task) => taskNumber(task, sections.value)
  const taskSection = (task) => sectionForTask(task, sections.value)
  const sectionTasks = (id) => canonicalOrder(tasks.value.filter((task) => task.sectionId === id))
  const counts = computed(() =>
    Object.fromEntries(
      FILTERS.map((filter) => [
        filter.key,
        tasks.value.filter((task) => filter.statuses.includes(task.status)).length,
      ]),
    ),
  )
  const progress = computed(() =>
    tasks.value.length ? Math.round((counts.value.done / tasks.value.length) * 100) : 0,
  )
  const findTask = (id) => tasks.value.find((task) => task.id === id)
  const addHistory = (task, type, text, metadata = {}) => {
    const createdAt = timestamp()
    task.history.push({
      id: uuid(),
      type,
      text,
      createdAt,
      actorType: currentMode.value,
      ...metadata,
    })
    task.updatedAt = createdAt
  }

  function saveToStorage() {
    if (API_MODE) return false
    if (storageBlocked.value) return false
    try {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({
          version: STORAGE_VERSION,
          project: project.value,
          sections: sections.value,
          tasks: tasks.value,
          currentMode: currentMode.value,
        }),
      )
      storageError.value = ''
      return true
    } catch {
      storageError.value = {
        messageKey: 'ui.yourChangesHaveNotBeenSavedBrowserStorage',
        params: {},
      }
      return false
    }
  }

  function loadFromStorage() {
    if (API_MODE) return
    loading = true
    try {
      const raw = localStorage.getItem(STORAGE_KEY)
      if (raw !== null) {
        const data = JSON.parse(raw)
        if (!isValidSnapshot(data)) throw new Error('Invalid saved project')
        migrateLocalStructure(data)
        project.value = data.project
        sections.value = data.sections
        tasks.value = data.tasks
        currentMode.value = data.currentMode
      }
      storageBlocked.value = false
      storageError.value = ''
    } catch {
      storageBlocked.value = true
      storageError.value = { messageKey: 'ui.weCouldNotReadTheSavedProjectYour', params: {} }
    } finally {
      loading = false
      initialized.value = true
    }
    if (!storageBlocked.value) saveToStorage()
  }

  function addTask(input) {
    if (API_MODE)
      return (async () => {
        try {
          const { data } = await api.request(
            admin.value ? `/api/admin/projects/${project.value.id}/tasks` : '/api/client/tasks',
            { method: 'POST', body: taskBody(input) },
          )
          replaceTask(data)
          return data.id
        } catch (error) {
          reportError(error)
          throw error
        }
      })()
    if (
      !isText(input.title) ||
      !isText(input.description) ||
      !input.title.trim() ||
      !input.description.trim()
    )
      throw new Error(t('ui.addATitleAndDescriptionForYourIdea'))
    const location = input.location ?? input.section
    if (!isText(location) || !location.trim()) throw new Error(t('ui.enterAPlaceOnTheWebsiteOrA'))
    if (!Object.hasOwn(PRIORITIES, input.priority)) throw new Error(t('ui.checkTheIdeaSImportance'))
    if (
      input.attachments &&
      (!Array.isArray(input.attachments) || !input.attachments.every(validAttachment))
    )
      throw new Error(t('ui.checkTheAttachedFiles'))
    const section = ensureLocalSection((input.section ?? location).trim())
    normalizePositions(canonicalOrder(sections.value))
    for (const item of sections.value) normalizePositions(sectionTasks(item.id))
    const createdAt = timestamp()
    const task = {
      id: uuid(),
      title: input.title.trim().slice(0, 160),
      location: location.trim(),
      section: section.name,
      sectionId: section.id,
      position: Math.max(0, ...sectionTasks(section.id).map((item) => item.position)) + 1,
      description: input.description.trim().slice(0, 10000),
      expectedResult: (input.expectedResult || '').trim().slice(0, 10000),
      priority: input.priority,
      status: 'new',
      attachments: input.attachments || [],
      clientApproved: false,
      estimateHours: null,
      price: null,
      developerNotes: '',
      comments: [],
      history: [
        {
          id: uuid(),
          type: 'created',
          text: t('ui.addedANewIdea', {
            arg0: isDeveloper.value ? t('ui.developer') : t('ui.client'),
          }),
          createdAt,
        },
      ],
      createdAt,
      updatedAt: createdAt,
    }
    tasks.value.unshift(task)
    return task.id
  }

  function updateTask(id, changes) {
    if (API_MODE) return mutateTask(id, '', 'PATCH', changes)
    const task = findTask(id)
    if (!task || (!isDeveloper.value && !canEditClientTask(task))) return false
    const fields = [
      'title',
      'description',
      'expectedResult',
      'location',
      'section',
      'priority',
      'attachments',
    ]
    const safe = Object.fromEntries(
      fields.filter((key) => key in changes).map((key) => [key, changes[key]]),
    )
    if ('location' in safe || 'section' in safe) {
      const location = 'location' in safe ? safe.location : safe.section
      if (!isText(location) || !location.trim()) return false
      safe.location = location.trim()
      delete safe.section
      safe.targetSectionName = isText(changes.section)
        ? changes.section.trim()
        : !isDeveloper.value
          ? safe.location
          : undefined
    }
    const candidate = { ...task, ...safe }
    if (
      !isText(candidate.title) ||
      !candidate.title.trim() ||
      !isText(candidate.description) ||
      !candidate.description.trim() ||
      !isText(candidate.expectedResult) ||
      !isText(getTaskLocation(candidate)) ||
      !getTaskLocation(candidate).trim() ||
      !Object.hasOwn(PRIORITIES, candidate.priority) ||
      !Array.isArray(candidate.attachments) ||
      !candidate.attachments.every(validAttachment)
    )
      return false
    if (Object.keys(safe).every((key) => JSON.stringify(task[key]) === JSON.stringify(safe[key])))
      return false
    const targetName = safe.targetSectionName
    delete safe.targetSectionName
    if (targetName) {
      const section = ensureLocalSection(targetName)
      if (task.sectionId !== section.id) {
        const oldId = task.sectionId
        task.sectionId = section.id
        task.position =
          Math.max(
            0,
            ...sectionTasks(section.id)
              .filter((item) => item.id !== id)
              .map((item) => item.position),
          ) + 1
        normalizePositions(sectionTasks(oldId))
        normalizePositions(sectionTasks(section.id))
      }
      normalizePositions(canonicalOrder(sections.value))
    }
    Object.assign(task, safe)
    addHistory(task, 'updated', t('ui.ideaDescriptionUpdated'))
    return true
  }

  function deleteTask(id) {
    const task = findTask(id)
    if (!task || storageBlocked.value || (!isDeveloper.value && !canEditClientTask(task)))
      return false
    if (API_MODE)
      return (async () => {
        try {
          await serialize(id, () => api.request(`/api/client/tasks/${id}`, { method: 'DELETE' }))
          await loadApiProject(project.value.id, false)
          return true
        } catch (error) {
          reportError(error)
          return false
        }
      })()
    const sectionId = task.sectionId
    tasks.value = tasks.value.filter((item) => item.id !== id)
    normalizePositions(sectionTasks(sectionId))
    normalizePositions(canonicalOrder(sections.value))
    return true
  }
  function ensureLocalSection(name) {
    let section = sections.value.find((item) => item.name.trim() === name)
    if (!section) {
      section = {
        id: uuid(),
        name,
        position: Math.max(0, ...sections.value.map((item) => item.position)) + 1,
      }
      sections.value.push(section)
    }
    return section
  }
  async function selectLocale(value) {
    if (!SUPPORTED_LOCALES.includes(value)) return false
    setManualLocale(value)
    if (API_MODE && currentClient.value && !admin.value) {
      try {
        const id = currentClient.value.id
        await serialize(`locale:${id}`, async () => {
          const result = await api.request('/api/client/me/locale', {
            method: 'PATCH',
            body: { locale: value },
          })
          if (currentClient.value?.id === id)
            currentClient.value.preferredLocale = result.preferredLocale
        })
      } catch (error) {
        reportError(error)
      }
    }
  }
  async function reorderSection(id, direction) {
    if (!isDeveloper.value || storageBlocked.value || orderPending.value) return false
    const previous = sections.value.map((section) => ({
      id: section.id,
      position: section.position,
    }))
    if (!swapPosition(canonicalOrder(sections.value), id, direction)) return false
    if (!API_MODE) return true
    orderPending.value = true
    try {
      const result = await api.request(`/api/admin/projects/${project.value.id}/sections/${id}`, {
        method: 'PATCH',
        body: { direction },
      })
      sections.value = result.data
      return true
    } catch (error) {
      for (const saved of previous) {
        const section = sections.value.find((item) => item.id === saved.id)
        if (section) section.position = saved.position
      }
      reportError(error)
      return false
    } finally {
      orderPending.value = false
    }
  }

  function changeStatus(id, status) {
    if (API_MODE)
      return !isDeveloper.value || findTask(id)?.status === status
        ? false
        : mutateTask(id, '', 'PATCH', { status })
    const task = findTask(id)
    if (!isDeveloper.value || !task || !Object.hasOwn(STATUSES, status) || task.status === status)
      return false
    const oldValue = task.status
    const previous = STATUSES[task.status].label
    task.status = status
    addHistory(
      task,
      'status',
      t('ui.statusChanged', { arg0: previous, arg1: STATUSES[status].label }),
      { oldValue, newValue: status },
    )
    return true
  }

  function approveTask(id) {
    if (API_MODE) return isDeveloper.value ? false : mutateTask(id, '/approve', 'POST')
    const task = findTask(id)
    if (isDeveloper.value || !task || task.clientApproved) return false
    task.clientApproved = true
    addHistory(task, 'approved', t('ui.clientApprovedTheIdea'))
    // Approval by the client is independent of the developer's workflow status.
    return true
  }

  function addComment(id, text) {
    if (API_MODE)
      return text.trim() ? mutateTask(id, '/comments', 'POST', { text: text.trim() }) : false
    const task = findTask(id)
    if (!task || !isText(text) || !text.trim()) return false
    task.comments.push({
      id: uuid(),
      author: currentMode.value,
      text: text.trim().slice(0, 5000),
      createdAt: timestamp(),
    })
    addHistory(
      task,
      'comment',
      t('ui.addedAComment', { arg0: isDeveloper.value ? t('ui.developer') : t('ui.client') }),
    )
    return true
  }

  function updateDeveloperData(id, data) {
    if (API_MODE) {
      if (!isDeveloper.value || !findTask(id)) return false
      const safe = Object.fromEntries(
        Object.entries(data).filter(([key, value]) =>
          key === 'developerNotes'
            ? isText(value)
            : ['estimateHours', 'price'].includes(key) && validNumber(value),
        ),
      )
      developerDrafts.value[id] = { ...(developerDrafts.value[id] || {}), ...safe }
      Object.assign(findTask(id), safe)
      clearTimeout(developerTimer)
      developerTimer = setTimeout(flushDeveloperData, 450)
      return true
    }
    const task = findTask(id)
    if (!isDeveloper.value || !task) return false
    const changed = []
    for (const [key, label] of [
      ['estimateHours', t('ui.hourEstimate')],
      ['price', t('ui.price3')],
      ['developerNotes', t('ui.technicalNotes2')],
    ]) {
      if (!(key in data) || data[key] === task[key]) continue
      if (key === 'developerNotes' ? !isText(data[key]) : !validNumber(data[key])) continue
      task[key] = data[key]
      changed.push(label)
    }
    if (!changed.length) return false
    // Group uninterrupted typing into one history entry, while persisting every input.
    const previous = task.history.at(-1)
    const createdAt = timestamp()
    if (previous?.type === 'developer' && Date.now() - Date.parse(previous.createdAt) < 30000) {
      const labels = new Set([
        ...previous.text.replace(t('ui.developerUpdated'), '').split(', '),
        ...changed,
      ])
      previous.text = t('ui.developerUpdated2', { arg0: [...labels].join(', ') })
      previous.createdAt = createdAt
      task.updatedAt = createdAt
    } else addHistory(task, 'developer', t('ui.developerUpdated2', { arg0: changed.join(', ') }))
    return true
  }

  async function moveTask(id, sectionId) {
    if (!isDeveloper.value || storageBlocked.value) return false
    if (API_MODE) {
      await flushDeveloperData()
      if (!(await mutateTask(id, '/move', 'POST', { sectionId }))) return false
      await loadApiProject(project.value.id, true)
      return true
    }
    const task = findTask(id)
    const section = sections.value.find((item) => item.id === sectionId)
    if (!task || !section || task.sectionId === sectionId) return false
    const previous = numberForTask(task)
    const oldSection = task.sectionId
    task.sectionId = sectionId
    task.position =
      Math.max(
        0,
        ...sectionTasks(sectionId)
          .filter((item) => item.id !== id)
          .map((item) => item.position),
      ) + 1
    normalizePositions(sectionTasks(oldSection))
    normalizePositions(sectionTasks(sectionId))
    addHistory(
      task,
      'section_moved',
      t('ui.developerMovedTheIdeaTo', {
        arg0: section.name,
        arg1: previous,
        arg2: numberForTask(task),
      }),
      { oldValue: previous, newValue: numberForTask(task) },
    )
    return true
  }
  async function reorderTask(id, direction) {
    if (!isDeveloper.value || storageBlocked.value || !['up', 'down'].includes(direction))
      return false
    if (API_MODE) {
      if (orderPending.value) return false
      orderPending.value = true
      const previous = tasks.value.map((task) => ({ id: task.id, position: task.position }))
      swapPosition(sectionTasks(findTask(id).sectionId), id, direction)
      try {
        await flushDeveloperData()
        if (!(await mutateTask(id, '/reorder', 'POST', { direction }))) {
          for (const saved of previous) {
            const task = findTask(saved.id)
            if (task) task.position = saved.position
          }
          return false
        }
        await loadApiProject(project.value.id, true)
        return true
      } finally {
        orderPending.value = false
      }
    }
    const task = findTask(id)
    if (!task) return false
    const oldValue = numberForTask(task)
    if (!swapPosition(sectionTasks(task.sectionId), id, direction)) return false
    addHistory(
      task,
      'reordered',
      t('ui.developerChangedTheIdeaSOrder', { arg0: numberForTask(task) }),
      { oldValue, newValue: numberForTask(task) },
    )
    return true
  }

  function setMode(mode) {
    if (API_MODE) return
    if (['client', 'developer'].includes(mode)) currentMode.value = mode
  }

  watch(
    [project, sections, tasks, currentMode],
    () => {
      if (initialized.value && !loading) saveToStorage()
    },
    { deep: true, flush: 'sync' },
  )

  return {
    apiMode,
    admin,
    currentClient,
    selectLocale,
    orderPending,
    reorderSection,
    apiError,
    sessionLost,
    apiLoading,
    projectPath,
    newTaskPath,
    taskPath,
    loadApiProject,
    uploadAttachments,
    developerDrafts,
    savingDeveloper,
    flushDeveloperData,
    project,
    sections,
    tasks,
    currentMode,
    storageError,
    storageBlocked,
    initialized,
    isDeveloper,
    sectionOptions,
    numberForTask,
    taskSection,
    sectionTasks,
    moveTask,
    reorderTask,
    locationOptions,
    counts,
    progress,
    findTask,
    addTask,
    updateTask,
    deleteTask,
    changeStatus,
    approveTask,
    addComment,
    updateDeveloperData,
    setMode,
    loadFromStorage,
    saveToStorage,
  }
})
