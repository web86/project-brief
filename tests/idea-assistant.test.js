import { test } from 'node:test'
import assert from 'node:assert/strict'
import { effectScope, reactive } from 'vue'
import {
  createIdeaAssistant,
  useIdeaAssistant,
  validTaskSuggestion,
} from '../src/services/ideaAssistant.js'
import { createApiClient } from '../src/api/client.js'
import { locale, formatApiError } from '../src/i18n/index.js'

const suggestion = () => ({
  title: 'Увеличить фото',
  description: 'Увеличить фотографии товара.',
  expectedResult: 'Фотографии хорошо видны.',
  questions: ['Какой размер нужен?'],
  suggestedIdeas: [{ title: 'Меню', description: 'Изменить меню.', expectedResult: '' }],
})
const input = () => ({
  title: 'Фото',
  description: 'Хочу крупнее фото',
  expectedResult: '',
  section: 'Каталог',
  location: '/catalog',
  developerNotes: 'PRIVATE-NOTES',
  attachments: ['private.png'],
  comments: ['private'],
})
const context = () => ({ apiMode: true, projectId: 'project-uuid', isDeveloper: false })
function deferred() {
  let resolve, reject
  const promise = new Promise((ok, fail) => {
    resolve = ok
    reject = fail
  })
  return { promise, resolve, reject }
}
function session(t, help = async () => suggestion()) {
  const form = reactive(input())
  const actor = reactive(context())
  const applications = []
  const scope = effectScope()
  const state = scope.run(() =>
    useIdeaAssistant({
      source: () => form,
      context: () => actor,
      assistant: { help },
      apply: (values) => {
        applications.push(values)
        Object.assign(form, values)
      },
    }),
  )
  t.after(() => {
    state.dispose()
    scope.stop()
  })
  return { state, form, actor, applications }
}

test('real API adapter bootstraps CSRF and sends only selected text through scoped routes', async () => {
  for (const isDeveloper of [false, true]) {
    const requests = []
    const assistant = createIdeaAssistant(
      createApiClient({
        fetchImpl: async (path, options) => {
          requests.push({ path, ...options })
          return {
            ok: true,
            json: async () => (path === '/api/csrf' ? { token: 'csrf' } : suggestion()),
          }
        },
      }),
    )
    assert.deepEqual(await assistant.help(input(), { ...context(), isDeveloper }), suggestion())
    assert.equal(requests.length, 2)
    assert.equal(
      requests[1].path,
      isDeveloper
        ? '/api/admin/projects/project-uuid/ai/task-assistant'
        : '/api/client/project/project-uuid/ai/task-assistant',
    )
    assert.equal(requests[1].headers['X-CSRF-TOKEN'], 'csrf')
    assert.deepEqual(JSON.parse(requests[1].body), {
      title: 'Фото',
      description: 'Хочу крупнее фото',
      expectedResult: '',
      section: 'Каталог',
      location: '/catalog',
      consent: true,
    })
    assert.equal(requests[1].method, 'POST')
  }
})

test('demo and missing project never contact any API and never simulate a suggestion', async () => {
  let requests = 0
  const assistant = createIdeaAssistant({
    request: async () => {
      requests++
    },
  })
  await assert.rejects(assistant.help(input(), { ...context(), apiMode: false }), {
    code: 'ai_demo',
  })
  await assert.rejects(assistant.help(input(), { ...context(), projectId: null }), { status: 404 })
  assert.equal(requests, 0)
})

test('adapter rejects malformed, incomplete and excessive model responses', async () => {
  for (const result of [
    null,
    {},
    { ...suggestion(), title: 1 },
    { ...suggestion(), description: ' ' },
    { ...suggestion(), questions: {} },
    { ...suggestion(), questions: Array(6).fill('Why?') },
    { ...suggestion(), title: 'я'.repeat(161) },
    { ...suggestion(), description: 'x'.repeat(10001) },
    { ...suggestion(), expectedResult: 'x'.repeat(10001) },
    { ...suggestion(), questions: ['x'.repeat(1001)] },
    { ...suggestion(), suggestedIdeas: Array(4).fill(suggestion().suggestedIdeas[0]) },
    { ...suggestion(), suggestedIdeas: [{ title: 'Incomplete' }] },
  ]) {
    assert.equal(validTaskSuggestion(result), false)
    const assistant = createIdeaAssistant({ request: async () => result })
    await assert.rejects(assistant.help(input(), context()), { code: 'ai_invalid_response' })
  }
  assert.equal(validTaskSuggestion({ ...suggestion(), expectedResult: '' }), true)
})

test('opening requires source text and does not send until explicit confirmation', async (t) => {
  let requests = 0
  const { state, form } = session(t, async () => {
    requests++
    return suggestion()
  })
  Object.assign(form, { title: '', description: ' ', expectedResult: '' })
  state.show()
  assert.equal(state.open.value, false)
  form.expectedResult = 'Нужен результат'
  state.show()
  assert.equal(state.open.value, true)
  assert.equal(requests, 0)
  assert.equal(await state.generate(), true)
  assert.equal(requests, 1)
})

test('proposal is editable and applies only text locally after an explicit action', async (t) => {
  const { state, form, applications } = session(t)
  const original = { ...form }
  state.show()
  await state.generate()
  assert.deepEqual({ ...form }, original)
  assert.equal(applications.length, 0)
  state.draft.value.title = 'Исправлено вручную'
  assert.equal(state.applyResult(), true)
  assert.deepEqual(applications, [
    {
      title: 'Исправлено вручную',
      description: 'Увеличить фотографии товара.',
      expectedResult: 'Фотографии хорошо видны.',
    },
  ])
  assert.equal(form.section, original.section)
  assert.equal(form.location, original.location)
  assert.deepEqual(form.attachments, original.attachments)
  assert.equal(state.open.value, false)
  assert.equal(state.applyResult(), false)
})

test('invalid edits cannot overwrite the form and cancel preserves the existing draft', async (t) => {
  const { state, form, applications } = session(t)
  const original = { ...form }
  state.show()
  await state.generate()
  state.draft.value.title = ''
  assert.equal(state.applyResult(), false)
  state.draft.value.description = 'Edited but cancelled'
  state.close()
  assert.deepEqual({ ...form }, original)
  assert.deepEqual(applications, [])
  assert.equal(state.response.value, null)
})

test('repeated generate clicks issue one request and a late response cannot reopen a closed dialog', async (t) => {
  const request = deferred()
  let calls = 0
  const { state, applications } = session(t, () => {
    calls++
    return request.promise
  })
  state.show()
  const first = state.generate()
  assert.equal(state.pending.value, true)
  assert.equal(await state.generate(), false)
  state.close()
  state.show()
  assert.equal(state.open.value, false)
  assert.equal(await state.generate(), false)
  request.resolve(suggestion())
  assert.equal(await first, false)
  assert.equal(calls, 1)
  assert.equal(state.open.value, false)
  assert.equal(state.pending.value, false)
  assert.equal(state.response.value, null)
  assert.deepEqual(applications, [])
  state.show()
  assert.equal(state.open.value, true)
})

test('changes to every source field or project while pending invalidate the response', async (t) => {
  for (const field of [
    'title',
    'description',
    'expectedResult',
    'section',
    'location',
    'projectId',
    'isDeveloper',
  ]) {
    const request = deferred()
    const { state, form, actor, applications } = session(t, () => request.promise)
    state.show()
    const pending = state.generate()
    if (field === 'projectId') actor.projectId = 'another-project'
    else if (field === 'isDeveloper') actor.isDeveloper = true
    else form[field] += ' changed'
    request.resolve(suggestion())
    assert.equal(await pending, false, field)
    assert.equal(state.stale.value, true, field)
    assert.equal(state.applyResult(), false, field)
    assert.deepEqual(applications, [])
    assert.equal(state.response.value, null)
  }
})

test('source changed after generation prevents applying an outdated proposal even if changed back', async (t) => {
  const { state, form } = session(t)
  state.show()
  await state.generate()
  const original = form.title
  form.title = 'New title'
  form.title = original
  assert.equal(state.applyResult(), false)
  state.close()
  state.show()
  await state.generate()
  assert.equal(state.applyResult(), true)
})

test('errors preserve form values and a later explicit retry can succeed', async (t) => {
  let calls = 0
  const failure = { code: 'ai_disabled', status: 503 }
  const { state, form } = session(t, async () => {
    if (!calls++) throw failure
    return suggestion()
  })
  const original = { ...form }
  state.show()
  assert.equal(await state.generate(), false)
  assert.equal(state.error.value.code, failure.code)
  assert.equal(state.pending.value, false)
  assert.deepEqual({ ...form }, original)
  assert.equal(await state.generate(), true)
  assert.equal(state.error.value, null)
})

test('unmount ignores late failures and preserves the original draft', async (t) => {
  const request = deferred()
  const { state, form } = session(t, () => request.promise)
  const original = { ...form }
  state.show()
  const pending = state.generate()
  state.dispose()
  request.reject(new Error('late failure'))
  assert.equal(await pending, false)
  assert.equal(state.error.value, null)
  assert.equal(state.open.value, false)
  assert.deepEqual({ ...form }, original)
})

test('interface locale does not change the source, response language or invalidate a proposal', async (t) => {
  const request = deferred()
  const { state, form } = session(t, (text) => {
    assert.equal(text.description, form.description)
    assert.equal(Object.hasOwn(text, 'locale'), false)
    return request.promise
  })
  state.show()
  const pending = state.generate()
  locale.value = 'en'
  request.resolve(suggestion())
  await pending
  assert.equal(state.draft.value.title, 'Увеличить фото')
  assert.equal(state.stale.value, false)
  for (const language of ['ru', 'en']) {
    locale.value = language
    for (const code of [
      'ai_disabled',
      'ai_demo',
      'ai_timeout',
      'ai_unavailable',
      'ai_invalid_response',
      'ai_provider_limit',
      'ai_rate_limit',
      'ai_daily_limit',
    ]) {
      assert.ok(formatApiError({ code, status: 503 }))
      assert.notEqual(formatApiError({ code, status: 503 }), `ai.errors.${code}`)
    }
  }
})
