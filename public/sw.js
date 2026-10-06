/* Push-only worker: no fetch handler, application cache, or private API data. */
self.addEventListener('push', (event) => {
  let payload
  try {
    payload = event.data?.json()
  } catch {
    return
  }
  if (!payload || typeof payload.title !== 'string') return
  event.waitUntil(
    self.registration.showNotification(payload.title, {
      body: typeof payload.body === 'string' ? payload.body : '',
      icon: '/icon-192.png',
      badge: '/icon-192.png',
      tag: typeof payload.tag === 'string' ? payload.tag : 'projectbrief',
      data: { url: safeUrl(payload.url) },
    }),
  )
})
function safeUrl(value) {
  try {
    const url = new URL(value, self.location.origin)
    if (
      url.origin === self.location.origin &&
      /^\/(?:project|admin\/projects)\/[a-f0-9-]{36}(?:\/(?:brief\/)?task\/[a-f0-9-]{36})?$/.test(
        url.pathname,
      )
    )
      return url.href
  } catch {}
  return self.location.origin + '/'
}
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = safeUrl(event.notification.data?.url)
  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      const target =
        windows.find((client) => client.url === url) ||
        windows.find((client) => {
          const current = new URL(client.url)
          return (
            current.origin === self.location.origin &&
            current.pathname.split('/').slice(0, 3).join('/') ===
              new URL(url).pathname.split('/').slice(0, 3).join('/')
          )
        })
      if (target) {
        if (target.url !== url) await target.navigate(url)
        return target.focus()
      }
      return self.clients.openWindow(url)
    })(),
  )
})
