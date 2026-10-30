import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createDrawerSwipe, attachDrawerSwipe } from '../src/utils/drawerSwipe.js'
function fixture() {
  let opened = false,
    mobile = true,
    canOpen = true,
    time = 0,
    modal = false,
    selection = true
  const calls = []
  const node = { closest: () => null, parentElement: null, scrollWidth: 100, clientWidth: 100 }
  const menu = {
    contains: (target) => target === node,
    getBoundingClientRect: () => ({ left: 0, right: 320 }),
  }
  const env = {
    document: { body: {}, querySelector: () => (modal ? {} : null) },
    getComputedStyle: () => ({ overflowX: 'auto' }),
    getSelection: () => ({ isCollapsed: selection }),
  }
  const swipe = createDrawerSwipe({
    mobile: () => mobile,
    isOpen: () => opened,
    canOpen: () => canOpen,
    open: () => {
      opened = true
      calls.push('open')
    },
    close: () => {
      opened = false
      calls.push('close')
    },
    dialog: () => menu,
    env,
    now: () => time,
  })
  const point = (x, y = 100, extras = {}) => ({
    pointerType: 'touch',
    isPrimary: true,
    pointerId: 1,
    clientX: x,
    clientY: y,
    target: node,
    ...extras,
  })
  return {
    swipe,
    calls,
    point,
    node,
    set: (values) => {
      ;({
        opened = opened,
        mobile = mobile,
        canOpen = canOpen,
        time = time,
        modal = modal,
        selection = selection,
      } = values)
    },
  }
}
test('edge touch opens the existing drawer, left swipe inside it closes, desktop/mouse never open', () => {
  const f = fixture()
  f.swipe.down(f.point(24))
  f.swipe.move(f.point(65))
  f.swipe.up(f.point(110))
  f.swipe.down(f.point(240))
  f.swipe.up(f.point(130))
  assert.deepEqual(f.calls, ['open', 'close'])
  for (const extras of [{ pointerType: 'mouse' }, { pointerType: 'pen' }, { isPrimary: false }]) {
    f.swipe.down(f.point(20, 100, extras))
    f.swipe.up(f.point(150))
  }
  f.set({ mobile: false })
  f.swipe.down(f.point(20))
  f.swipe.up(f.point(150))
  assert.equal(f.calls.length, 2)
})
test('vertical scrolling, diagonal/short/reversed/late gestures and text selection do not open', () => {
  for (const [start, end] of [
    [29, 130],
    [20, 60],
    [20, -60],
  ]) {
    const f = fixture()
    f.swipe.down(f.point(start))
    f.swipe.up(f.point(end))
    assert.deepEqual(f.calls, [])
  }
  const f = fixture()
  f.swipe.down(f.point(20))
  f.swipe.move(f.point(22, 122))
  f.swipe.up(f.point(160, 122))
  f.swipe.down(f.point(20))
  f.swipe.up(f.point(110, 155))
  f.swipe.down(f.point(20))
  f.set({ time: 900 })
  f.swipe.up(f.point(160))
  f.set({ selection: false })
  f.swipe.down(f.point(20))
  f.swipe.up(f.point(160))
  assert.deepEqual(f.calls, [])
})
test('controls, horizontal scroll regions, other modals and browser pointercancel remain authoritative', () => {
  const f = fixture()
  f.node.closest = () => ({})
  f.swipe.down(f.point(20))
  f.swipe.up(f.point(160))
  f.node.closest = () => null
  f.node.scrollWidth = 300
  f.swipe.down(f.point(20))
  f.swipe.up(f.point(160))
  f.node.scrollWidth = 100
  f.set({ modal: true })
  f.swipe.down(f.point(20))
  f.swipe.up(f.point(160))
  f.set({ modal: false })
  f.swipe.down(f.point(20))
  f.swipe.cancel()
  f.swipe.up(f.point(160))
  f.swipe.down(f.point(20))
  f.swipe.down(f.point(30, 100, { isPrimary: false, pointerId: 2 }))
  f.swipe.up(f.point(160))
  f.set({ canOpen: false })
  f.swipe.down(f.point(20))
  f.swipe.up(f.point(160))
  assert.deepEqual(f.calls, [])
})
test('passive handlers preserve browser defaults and dispose cleanly on component unmount', () => {
  const calls = [],
    events = new Map()
  const env = {
    document: {
      addEventListener: (name, fn, options) => {
        events.set(name, fn)
        calls.push(options)
      },
      removeEventListener: (name) => events.delete(name),
    },
    addEventListener() {},
    removeEventListener() {},
  }
  const dispose = attachDrawerSwipe({
    env,
    mobile: () => true,
    isOpen: () => false,
    open() {},
    close() {},
    dialog: () => null,
  })
  assert.equal(events.size, 8)
  assert.equal(
    calls.every((options) => options.passive === true),
    true,
  )
  dispose()
  assert.equal(events.size, 0)
})

test('native pointercancel from panning allows passive touch fallback, while touchcancel/navigation cancel both streams', () => {
  const handlers = new Map(),
    globals = new Map(),
    calls = []
  let opened = false
  const target = { closest: () => null, parentElement: null, scrollWidth: 100, clientWidth: 100 }
  const env = {
    document: {
      body: {},
      querySelector: () => null,
      addEventListener: (key, fn) => handlers.set(key, fn),
      removeEventListener() {},
    },
    addEventListener: (key, fn) => globals.set(key, fn),
    removeEventListener() {},
  }
  const dispose = attachDrawerSwipe({
    env,
    mobile: () => true,
    isOpen: () => opened,
    open: () => {
      opened = true
      calls.push('open')
    },
    close() {},
    dialog: () => null,
  })
  const event = (x) => ({
    target,
    changedTouches: [{ identifier: 1, clientX: x, clientY: 100 }],
    touches: [{}],
  })
  handlers.get('touchstart')(event(8))
  handlers.get('pointercancel')()
  handlers.get('touchmove')(event(100))
  handlers.get('touchend')(event(150))
  assert.deepEqual(calls, ['open'])
  opened = false
  for (const cancel of [
    () => handlers.get('touchcancel')(),
    () => globals.get('popstate')(),
    () => globals.get('pagehide')(),
  ]) {
    handlers.get('touchstart')(event(8))
    cancel()
    handlers.get('touchend')(event(150))
  }
  assert.deepEqual(calls, ['open'])
  dispose()
})
