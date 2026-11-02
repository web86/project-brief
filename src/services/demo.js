import { reactive } from 'vue'
export const STATIC_DEMO = import.meta.env?.VITE_STATIC_DEMO === 'true'
export const demo = reactive({ enabled: false, readOnly: false })
export async function initializeDemo() {
  if (STATIC_DEMO) {
    Object.assign(demo, { enabled: true, readOnly: true })
    const disableUploads = () =>
      document.querySelectorAll('input[type=file]').forEach((input) => {
        input.disabled = true
      })
    new MutationObserver(disableUploads).observe(document.documentElement, {
      childList: true,
      subtree: true,
    })
    disableUploads()
    return
  }
  if (globalThis.document?.querySelector('meta[name="projectbrief-demo"]')?.content !== 'enabled')
    return
  const response = await fetch('/api/demo/status', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
  if (response.ok) Object.assign(demo, await response.json())
}
export function blockDemoExternalLink(event) {
  if (!demo.enabled) return
  const anchor = event.target.closest?.('a[href]')
  if (anchor && new URL(anchor.href, location.href).origin !== location.origin)
    event.preventDefault()
}
