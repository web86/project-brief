import { onUnmounted, ref } from 'vue'

export const taskDragKey = Symbol('taskDrag')

export function useTaskDrag(store, enabled) {
  const dragging = ref('')
  const target = ref(null)
  let gesture = null
  let ghost = null
  let frame = 0
  let suppressUntil = 0

  function locate() {
    if (!gesture || !dragging.value) return
    const { x, y, id } = gesture
    ghost.style.transform = `translate(${x - gesture.offsetX}px, ${y - gesture.offsetY}px) rotate(1deg)`
    const hit = document.elementFromPoint(x, y)
    const section = hit?.closest('[data-drop-section]')
    if (!section) {
      target.value = null
      return
    }
    const sectionId = section.dataset.dropSection
    const card = hit.closest('[data-task-id]')
    if (card && card.dataset.taskId !== id) {
      const rect = card.getBoundingClientRect()
      const singleColumn =
        getComputedStyle(card.parentElement).gridTemplateColumns.trim().split(/\s+/).length === 1
      const before = singleColumn ? y < rect.top + rect.height / 2 : x < rect.left + rect.width / 2
      target.value = {
        sectionId,
        beforeTaskId: before ? card.dataset.taskId : null,
        afterTaskId: before ? null : card.dataset.taskId,
      }
    } else if (!card) {
      target.value = { sectionId, beforeTaskId: null, afterTaskId: null }
    } else target.value = null
  }
  function tick() {
    if (!gesture || !dragging.value) return
    const y = gesture.y
    const edge = 70
    const speed =
      y < edge
        ? -Math.ceil((edge - y) / 5)
        : y > innerHeight - edge
          ? Math.ceil((y - innerHeight + edge) / 5)
          : 0
    if (speed) window.scrollBy(0, speed)
    locate()
    frame = requestAnimationFrame(tick)
  }
  function cleanup() {
    cancelAnimationFrame(frame)
    window.removeEventListener('pointermove', move)
    window.removeEventListener('pointerup', end)
    window.removeEventListener('pointercancel', cancel)
    window.removeEventListener('keydown', keydown)
    window.removeEventListener('blur', cancel)
    if (gesture?.handle.hasPointerCapture?.(gesture.pointerId))
      gesture.handle.releasePointerCapture(gesture.pointerId)
    ghost?.remove()
    document.body.classList.remove('task-drag-active')
    ghost = null
    gesture = null
    dragging.value = ''
    target.value = null
  }
  function move(event) {
    if (!gesture || event.pointerId !== gesture.pointerId) return
    gesture.x = event.clientX
    gesture.y = event.clientY
    if (!dragging.value) {
      if (Math.hypot(gesture.x - gesture.startX, gesture.y - gesture.startY) < 10) return
      if (!enabled.value || store.orderPending) return cleanup()
      dragging.value = gesture.id
      ghost = gesture.card.cloneNode(true)
      ghost.querySelectorAll('dialog, .task-move-control').forEach((node) => node.remove())
      ghost.removeAttribute('data-task-id')
      ghost.setAttribute('aria-hidden', 'true')
      ghost.classList.add('task-drag-ghost')
      Object.assign(ghost.style, { width: `${gesture.width}px`, height: `${gesture.height}px` })
      document.body.append(ghost)
      document.body.classList.add('task-drag-active')
      frame = requestAnimationFrame(tick)
    }
    event.preventDefault()
    locate()
  }
  function cancel() {
    if (dragging.value) suppressUntil = Date.now() + 400
    cleanup()
  }
  function keydown(event) {
    if (event.key === 'Escape') {
      event.preventDefault()
      cancel()
    }
  }
  function end(event) {
    if (!gesture || event.pointerId !== gesture.pointerId) return
    const id = gesture.id
    const destination = target.value
    const wasDragging = !!dragging.value
    if (wasDragging) {
      event.preventDefault()
      suppressUntil = Date.now() + 400
    }
    cleanup()
    if (wasDragging && destination && enabled.value)
      void store.placeTask(
        id,
        destination.sectionId,
        destination.beforeTaskId,
        destination.afterTaskId,
      )
  }
  function begin(event, id) {
    if (
      !enabled.value ||
      store.orderPending ||
      store.storageBlocked ||
      event.button !== 0 ||
      event.isPrimary === false
    )
      return
    cleanup()
    const handle = event.currentTarget
    const card = handle.closest('[data-task-id]')
    const rect = card.getBoundingClientRect()
    gesture = {
      id,
      handle,
      card,
      pointerId: event.pointerId,
      x: event.clientX,
      y: event.clientY,
      startX: event.clientX,
      startY: event.clientY,
      offsetX: event.clientX - rect.left,
      offsetY: event.clientY - rect.top,
      width: rect.width,
      height: rect.height,
    }
    handle.setPointerCapture(event.pointerId)
    window.addEventListener('pointermove', move, { passive: false })
    window.addEventListener('pointerup', end)
    window.addEventListener('pointercancel', cancel)
    window.addEventListener('keydown', keydown)
    window.addEventListener('blur', cancel)
  }
  onUnmounted(cleanup)
  return { dragging, target, begin, suppressClick: () => Date.now() < suppressUntil, enabled }
}
