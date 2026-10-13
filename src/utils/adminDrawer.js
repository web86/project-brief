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
