// Resolve browser tags without treating Traditional Chinese as a Simplified Chinese preference.
export function resolveBrowserLocale(value, catalog) {
  if (typeof value !== 'string') return 'en'
  const tag = value.trim().replaceAll('_', '-').toLowerCase()
  const parts = tag.split('-')
  if (parts[0] === 'zh' && parts.includes('hant')) return 'en'
  return (
    catalog.find(({ code, browserAliases = [] }) =>
      [code, ...browserAliases].some((alias) => {
        const candidate = alias.toLowerCase()
        return tag === candidate || tag.startsWith(candidate + '-')
      }),
    )?.code || 'en'
  )
}
