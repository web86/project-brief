import { ref } from 'vue'

// Native modal dialog makes off-screen/background content inert and handles focus containment.
export function createAdminDrawer({ dialog, trigger }) {
  const open = ref(false)
  function show() {
    dialog()?.showModal()
    open.value = true
  }
  function close(restore = true) {
    dialog()?.close()
    open.value = false
    if (restore) trigger()?.focus()
  }
  function keydown(event) {
    if (event.key === 'Tab' && open.value) {
      const element = dialog()
      const controls = Array.from(
        element.querySelectorAll(
          'a[href], button:not([disabled]), select:not([disabled]), textarea:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
      ).filter((control) => control.getClientRects().length)
      const first = controls[0],
        last = controls.at(-1)
      const active = element.ownerDocument.activeElement
      if (first && ((event.shiftKey && active === first) || (!event.shiftKey && active === last))) {
        event.preventDefault()
        ;(event.shiftKey ? last : first).focus()
      }
    }
    if (event.key === 'Escape' && open.value) {
      event.preventDefault()
      close()
    }
  }
  function backdrop(event) {
    if (event.target === dialog()) close()
  }
  return { open, show, close, keydown, backdrop }
}
