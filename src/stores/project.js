import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import { api, API_MODE, taskBody } from '../api/client.js'
import { createDemoData } from '../data/demo.js'
import {
  FILTERS,
  getTaskLocation,
  PRIORITIES,
  STATUSES,
  STORAGE_KEY,
  STORAGE_VERSION,
} from '../constants/project.js'

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
  return (
    data?.version === STORAGE_VERSION &&
    data.project &&
    isText(data.project.id) &&
    isText(data.project.name) &&
    isText(data.project.website) &&
    isText(data.project.description) &&
    (data.project.currency === undefined || isText(data.project.currency)) &&
    Array.isArray(data.sections) &&
    data.sections.every(isText) &&
    ['client', 'developer'].includes(data.currentMode) &&
    Array.isArray(data.tasks) &&
    new Set(data.tasks.map((task) => task?.id)).size === data.tasks.length &&
    data.tasks.every(
      (task) =>
        task &&
        isText(task.id) &&
        isText(task.title) &&
        (task.section === undefined || isText(task.section)) &&
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
  const apiError = ref('')
  const sessionLost = ref(false)
  const apiLoading = ref(false)
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
    apiError.value = error.message
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
        const result = await api.request('/api/admin/me')
        admin.value = result.user
      } else {
        admin.value = null
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
    ...new Set([...sections.value, ...tasks.value.map(getTaskLocation)]),
  ])
  const sectionOptions = locationOptions
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
  const addHistory = (task, type, text) => {
    const createdAt = timestamp()
    task.history.push({ id: uuid(), type, text, createdAt })
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
      storageError.value =
        'Изменения пока не сохранены. Возможно, память браузера заполнена или недоступна. Не закрывайте страницу; проверьте доступ к памяти браузера или освободите место и повторите сохранение.'
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
        project.value = data.project
        sections.value = data.sections
        tasks.value = data.tasks
        currentMode.value = data.currentMode
      }
      storageBlocked.value = false
      storageError.value = ''
    } catch {
      storageBlocked.value = true
      storageError.value =
        'Не удалось прочитать сохранённый проект. Исходные данные не перезаписаны. Проверьте доступ к памяти браузера и обновите страницу. Сейчас показаны демонстрационные данные.'
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
      throw new Error('Добавьте название и описание идеи.')
    const location = input.location ?? input.section
    if (!isText(location) || !location.trim()) throw new Error('Укажите место на сайте или ссылку.')
    if (!Object.hasOwn(PRIORITIES, input.priority)) throw new Error('Проверьте важность идеи.')
    if (
      input.attachments &&
      (!Array.isArray(input.attachments) || !input.attachments.every(validAttachment))
    )
      throw new Error('Проверьте прикреплённые файлы.')
    const createdAt = timestamp()
    const task = {
      id: uuid(),
      title: input.title.trim().slice(0, 160),
      location: location.trim(),
      section: location.trim(),
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
          text: `${isDeveloper.value ? 'Разработчик' : 'Клиент'} добавил новую идею`,
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
    if (!task) return false
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
      safe.section = location.trim()
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
    Object.assign(task, safe)
    addHistory(task, 'updated', 'Описание идеи обновлено')
    return true
  }

  function deleteTask(id) {
    if (API_MODE) return false
    if (!isDeveloper.value || !findTask(id)) return false
    tasks.value = tasks.value.filter((task) => task.id !== id)
    return true
  }

  function changeStatus(id, status) {
    if (API_MODE)
      return !isDeveloper.value || findTask(id)?.status === status
        ? false
        : mutateTask(id, '', 'PATCH', { status })
    const task = findTask(id)
    if (!isDeveloper.value || !task || !Object.hasOwn(STATUSES, status) || task.status === status)
      return false
    const previous = STATUSES[task.status].label
    task.status = status
    addHistory(task, 'status', `Статус изменён: ${previous} → ${STATUSES[status].label}`)
    return true
  }

  function approveTask(id) {
    if (API_MODE) return isDeveloper.value ? false : mutateTask(id, '/approve', 'POST')
    const task = findTask(id)
    if (isDeveloper.value || !task || task.clientApproved) return false
    task.clientApproved = true
    addHistory(task, 'approved', 'Клиент согласовал задачу')
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
      `${isDeveloper.value ? 'Разработчик' : 'Клиент'} добавил комментарий`,
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
      ['estimateHours', 'оценка в часах'],
      ['price', 'стоимость'],
      ['developerNotes', 'технические заметки'],
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
        ...previous.text.replace('Разработчик обновил: ', '').split(', '),
        ...changed,
      ])
      previous.text = `Разработчик обновил: ${[...labels].join(', ')}`
      previous.createdAt = createdAt
      task.updatedAt = createdAt
    } else addHistory(task, 'developer', `Разработчик обновил: ${changed.join(', ')}`)
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
