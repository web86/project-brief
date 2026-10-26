// Only public font responses pass through this module, never document JSON.
export async function loadPublicFont(url, { storage = globalThis.caches, request = fetch } = {}) {
  let cache
  try {
    cache = await storage?.open('projectbrief-pdf-public-fonts-v1')
    const cached = await cache?.match(url)
    if (cached) return { response: cached, cached: true }
  } catch {
    // Private browsing, storage policies and quotas must not prevent an export.
  }
  const response = await request(url, { credentials: 'omit', cache: 'default' })
  if (response.ok && cache) {
    try {
      await cache.put(url, response.clone())
    } catch {
      // The downloaded font remains usable even if persistent caching fails.
    }
  }
  return { response, cached: false }
}
