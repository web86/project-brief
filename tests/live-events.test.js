import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createToastStore } from '../src/services/toast.js'
import { createLiveNotifications } from '../src/services/liveNotifications.js'
const event = (id, kind = 'created') => ({
  id,
  kind,
  task: 'Navigation',
  project: 'Studio',
  author: 'Anna',
  preview: '<img src=x onerror=alert(1)>',
  old: 'in_progress',
  status: 'done',
  url: '/project/018f214d-baf1-7000-8000-000000000001/task/018f214d-baf1-7000-8000-000000000002',
})
function setup(shared = new Map()) {
  const store = createToastStore(),
    opened = [],
    env = {
      document: { visibilityState: 'visible' },
      localStorage: {
        getItem: (key) => shared.get(key),
        setItem: (key, value) => shared.set(key, value),
      },
    }
  const notices = createLiveNotifications({ store, env, navigate: (url) => opened.push(url) })
  notices.start('workspace')
  return { store, env, notices, opened }
}
test('live notifications have localized status labels and clickable task/comment context', async () => {
  const f = setup()
  f.notices.show([event('one', 'status'), event('two', 'comment')])
  assert.equal(f.store.items.value.length, 2)
  assert.match(f.store.items.value[0].details[1](), /→/)
  await f.store.act(f.store.items.value[1].id)
  assert.equal(f.opened[0], event('two').url)
  f.store.clear()
})
test('overlapping polls and coordinated tabs cannot repeat the same notification', () => {
  const shared = new Map(),
    a = setup(shared),
    b = setup(shared)
  a.notices.show([event('one')])
  a.notices.show([event('one')])
  b.notices.show([event('one')])
  assert.equal(a.store.items.value.length, 1)
  assert.equal(b.store.items.value.length, 0)
  assert.equal([...shared.values()].join('').includes('Navigation'), false)
  a.store.clear()
  b.store.clear()
})
test('hidden pages suppress banners and identity changes clear sensitive previews', () => {
  const f = setup()
  f.env.document.visibilityState = 'hidden'
  f.notices.show([event('hidden')])
  assert.equal(f.store.items.value.length, 0)
  f.env.document.visibilityState = 'visible'
  f.notices.show([event('visible')])
  f.notices.start('different-workspace')
  assert.equal(f.store.items.value.length, 0)
  f.store.clear()
})
test('event bursts are grouped into at most three banners without removing ordinary notices', () => {
  const f = setup()
  f.store.success('Saved')
  f.notices.show(Array.from({ length: 20 }, (_, n) => event(String(n))))
  assert.equal(f.store.items.value.filter((item) => item.group === 'project-events').length, 3)
  assert.equal(f.store.items.value[0].message, 'Saved')
  f.notices.show([event('next')])
  assert.equal(f.store.items.value.filter((item) => item.group === 'project-events').length, 1)
  f.store.clear()
})
