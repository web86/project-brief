import { reactive } from 'vue'
export const demo = reactive({ enabled: false, readOnly: false })
export async function initializeDemo() {
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
