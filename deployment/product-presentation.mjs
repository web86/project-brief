// Rebrand current portable prose without changing code examples, IDs, paths or API headers.
const productText = (value) =>
  value
    .replace(/Project Brief/g, 'Dev2Done')
    .replace(/(?<![\w-])ProjectBrief(?![\w-])/g, 'Dev2Done')
export function productMarkdown(source) {
  let fenced = false
  return source
    .split(/(?<=\n)/)
    .map((line) => {
      if (/^\s*```/.test(line)) {
        fenced = !fenced
        return line
      }
      if (fenced) return line
      return line
        .split(/(`[^`]*`)/)
        .map((part, index) => (index % 2 ? part : productText(part)))
        .join('')
    })
    .join('')
}
export function productOpenApi(document) {
  const value = structuredClone(document)
  for (const key of ['title', 'description'])
    if (typeof value.info?.[key] === 'string') value.info[key] = productText(value.info[key])
  return value
}
