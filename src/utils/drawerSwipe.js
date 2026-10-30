// Passive pointer/touch events recognize a completed gesture; the browser keeps scrolling and system navigation.
export function createDrawerSwipe({
  mobile,
  isOpen,
  canOpen = () => true,
  open,
  close,
  dialog,
  env = globalThis,
  now = () => performance.now(),
}) {
  let gesture = null
  const cancel = () => {
    gesture = null
  }
  function excluded(target) {
    if (
      target?.closest?.(
        'input, textarea, select, button, a, label, [contenteditable]:not([contenteditable="false"]), [role="tablist"], [role="slider"], [aria-roledescription="carousel"], [data-no-drawer-swipe]',
      )
    )
      return true
    for (let node = target; node && node !== env.document?.body; node = node.parentElement) {
      if (
        node.scrollWidth > node.clientWidth + 2 &&
        /auto|scroll/.test(env.getComputedStyle?.(node)?.overflowX || '')
      )
        return true
    }
    return false
  }
  function down(event) {
    cancel()
    if (
      !mobile() ||
      event.pointerType !== 'touch' ||
      event.isPrimary === false ||
      excluded(event.target)
    )
      return
    const closing = isOpen()
    if (closing) {
      const element = dialog()
      const box = element?.getBoundingClientRect()
      if (
        !element?.contains(event.target) ||
        !box ||
        event.clientX < box.left ||
        event.clientX > box.right
      )
        return
    } else if (
      !canOpen() ||
      event.clientX < 0 ||
      event.clientX > 28 ||
      env.document?.querySelector('dialog[open]')
    )
      return
    gesture = { id: event.pointerId, x: event.clientX, y: event.clientY, time: now(), closing }
  }
  function move(event) {
    if (!gesture || event.pointerId !== gesture.id) return
    const dx = Math.abs(event.clientX - gesture.x),
      dy = Math.abs(event.clientY - gesture.y)
    if (dy > 12 && dy >= dx) cancel()
  }
  function up(event) {
    if (!gesture || event.pointerId !== gesture.id) return
    const start = gesture
    cancel()
    const dx = event.clientX - start.x,
      dy = Math.abs(event.clientY - start.y)
    if (
      !mobile() ||
      isOpen() !== start.closing ||
      now() - start.time > 800 ||
      dy > 40 ||
      Math.abs(dx) < 56 ||
      Math.abs(dx) < dy * 1.8 ||
      env.getSelection?.()?.isCollapsed === false
    )
      return
    if (start.closing && dx < 0) {
      close()
      return true
    } else if (
      !start.closing &&
      canOpen() &&
      dx > 0 &&
      !env.document?.querySelector('dialog[open]')
    ) {
      open()
      return true
    }
  }
  return { down, move, up, cancel }
}
export function attachDrawerSwipe(options) {
  const env = options.env || globalThis
  const target = env.document
  const pointer = createDrawerSwipe(options)
  const touch = createDrawerSwipe(options)
  const cancel = () => {
    pointer.cancel()
    touch.cancel()
  }
  function point(event) {
    const item = event.changedTouches?.[0]
    if (!item) return null
    return {
      pointerId: item.identifier,
      pointerType: 'touch',
      isPrimary: true,
      clientX: item.clientX,
      clientY: item.clientY,
      target: event.target,
    }
  }
  const handlers = {
    pointerdown: pointer.down,
    pointermove: pointer.move,
    pointerup: (event) => {
      if (pointer.up(event)) touch.cancel()
    },
    pointercancel: pointer.cancel,
    touchstart: (event) => {
      const value = point(event)
      if (event.touches.length === 1 && value) touch.down(value)
      else cancel()
    },
    touchmove: (event) => {
      const value = point(event)
      if (event.touches.length === 1 && value) touch.move(value)
      else cancel()
    },
    touchend: (event) => {
      const value = point(event)
      if (value && touch.up(value)) pointer.cancel()
    },
    touchcancel: cancel,
  }
  // Native panning may cancel Pointer Events while still delivering passive Touch Events.
  for (const [event, handler] of Object.entries(handlers))
    target?.addEventListener?.(event, handler, { passive: true })
  for (const event of ['blur', 'popstate', 'pagehide']) env.addEventListener?.(event, cancel)
  return () => {
    for (const [event, handler] of Object.entries(handlers))
      target?.removeEventListener?.(event, handler)
    for (const event of ['blur', 'popstate', 'pagehide']) env.removeEventListener?.(event, cancel)
    cancel()
  }
}
