import { computed, ref, watch } from 'vue'
import { api, ApiError } from '../api/client.js'

const taskFields = ['title', 'description', 'expectedResult']
const sourceFields = [...taskFields, 'section', 'location']
const textLength = (text) => [...text].length
export const taskText = (input) =>
  Object.fromEntries(taskFields.map((key) => [key, input[key] || '']))
export const assistantSource = (input) =>
  Object.fromEntries(sourceFields.map((key) => [key, input[key] || '']))

export function validTaskSuggestion(value) {
  const validTask = (task) =>
    task &&
    taskFields.every(
      (key) =>
        typeof task[key] === 'string' && textLength(task[key]) <= (key === 'title' ? 160 : 10000),
    ) &&
    task.title.trim() &&
    task.description.trim()
  return !!(
    validTask(value) &&
    Array.isArray(value.questions) &&
    value.questions.length <= 5 &&
    value.questions.every(
      (question) => typeof question === 'string' && question.trim() && textLength(question) <= 1000,
    ) &&
    Array.isArray(value.suggestedIdeas) &&
    value.suggestedIdeas.length <= 3 &&
    value.suggestedIdeas.every(validTask)
  )
}

export function createIdeaAssistant(client = api) {
  return {
    async help(input, { apiMode, projectId, isDeveloper }) {
      if (!apiMode) throw new ApiError(503, '', {}, 'ai_demo')
      if (!projectId) throw new ApiError(404, '')
      const project = encodeURIComponent(projectId)
      const path = isDeveloper
        ? `/api/admin/projects/${project}/ai/task-assistant`
        : `/api/client/project/${project}/ai/task-assistant`
      const result = await client.request(path, {
        method: 'POST',
        body: { ...assistantSource(input), consent: true },
        timeoutMs: 35000,
      })
      if (!validTaskSuggestion(result)) throw new ApiError(502, '', {}, 'ai_invalid_response')
      return result
    },
  }
}
export const ideaAssistant = createIdeaAssistant()

// Keep the proposal separate from the form until the user explicitly applies it.
export function useIdeaAssistant({ source, context, apply, assistant = ideaAssistant }) {
  const open = ref(false)
  const pending = ref(false)
  const response = ref(null)
  const draft = ref(null)
  const error = ref(null)
  const stale = ref(false)
  let revision = 0
  const fingerprint = () => JSON.stringify([assistantSource(source()), context()])
  const stop = watch(
    fingerprint,
    () => {
      if (open.value) stale.value = true
    },
    { flush: 'sync' },
  )
  const hasText = computed(() => taskFields.some((key) => source()[key]?.trim()))
  const canApply = computed(
    () =>
      open.value &&
      !pending.value &&
      !stale.value &&
      response.value &&
      validTaskSuggestion({ ...response.value, ...draft.value }),
  )
  function show() {
    if (pending.value || !hasText.value) return
    open.value = true
    response.value = null
    draft.value = null
    error.value = null
    stale.value = false
  }
  function close() {
    revision++
    open.value = false
    response.value = null
    draft.value = null
    error.value = null
  }
  async function generate() {
    if (!open.value || pending.value || !hasText.value || response.value) return false
    const requestRevision = ++revision
    const input = assistantSource(source())
    const requestContext = { ...context() }
    stale.value = false
    error.value = null
    pending.value = true
    try {
      const result = await assistant.help(input, requestContext)
      if (requestRevision !== revision || !open.value || stale.value) return false
      if (!validTaskSuggestion(result)) throw new ApiError(502, '', {}, 'ai_invalid_response')
      response.value = result
      draft.value = taskText(result)
      return true
    } catch (cause) {
      if (requestRevision === revision && open.value) error.value = cause
      return false
    } finally {
      pending.value = false
    }
  }
  function applyResult() {
    if (!canApply.value) return false
    apply(taskText(draft.value))
    close()
    return true
  }
  function dispose() {
    close()
    stop()
  }
  return {
    open,
    pending,
    response,
    draft,
    error,
    stale,
    hasText,
    canApply,
    show,
    close,
    generate,
    applyResult,
    dispose,
  }
}
