const clone = (value) => JSON.parse(JSON.stringify(value))
export function newPlanConfiguration(definitions) {
  return {
    accessClass: 'free',
    visibility: 'public',
    translations: Object.fromEntries(
      ['ru', 'en', 'tr', 'zh-CN'].map((key) => [key, { name: '', description: '' }]),
    ),
    price: { mode: 'free', amountMinor: null, currency: null, scale: 2, interval: null },
    developerSeats: { included: 1, hardMax: 1, allowExtra: false },
    entitlements: definitions
      .filter((d) => d.availability === 'available' && !d.derived)
      .map((d) => ({ key: d.key, scope: 'workspace', value: clone(d.default) })),
  }
}
export function normalizedPlan(configuration) {
  const result = clone(configuration)
  for (const key of ['amountMinor', 'currency', 'interval'])
    if (result.price[key] === '') result.price[key] = null
  if (result.price.currency) result.price.currency = result.price.currency.toUpperCase()
  result.price.mode = result.accessClass === 'free' ? 'free' : 'paid'
  return result
}
export function setUnlimited(item, definition, enabled) {
  item.value = enabled ? { kind: 'unlimited' } : clone(definition.default)
}
export function createPlanRequests(request, uuid = () => crypto.randomUUID()) {
  const pending = new Map()
  return async (path, method, body, revision) => {
    const intent = JSON.stringify([path, method, body, revision])
    if (!pending.has(intent)) {
      if (pending.size >= 100) pending.delete(pending.keys().next().value)
      pending.set(intent, uuid())
    }
    const result = await request(path, {
      method,
      body,
      headers: {
        'Idempotency-Key': pending.get(intent),
        ...(revision == null ? {} : { 'If-Match': `"revision:${revision}"` }),
      },
    })
    pending.delete(intent)
    return result
  }
}
