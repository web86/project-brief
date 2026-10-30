// Only text and a fixed set of formatting marks cross the API boundary.
export function readAgreementEditor(root) {
  const blocks = []
  const types = { P: 'paragraph', DIV: 'paragraph', H2: 'heading', H3: 'subheading' }
  function spans(node, marks = {}) {
    if (node.nodeType === 3) return [{ text: node.textContent, ...marks }]
    if (node.nodeType !== 1 || ['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT'].includes(node.tagName))
      return []
    if (node.tagName === 'BR') return [{ text: '\n', ...marks }]
    const next = { ...marks }
    if (['B', 'STRONG'].includes(node.tagName)) next.bold = true
    if (['I', 'EM'].includes(node.tagName)) next.italic = true
    return [...node.childNodes].flatMap((child) => spans(child, next))
  }
  for (const node of root.childNodes) {
    if (['UL', 'OL'].includes(node.tagName)) {
      for (const item of node.children)
        blocks.push({ type: node.tagName === 'UL' ? 'bullet' : 'number', content: spans(item) })
    } else blocks.push({ type: types[node.tagName] || 'paragraph', content: spans(node) })
  }
  return blocks.filter((block) => block.content.some((span) => span.text.trim()))
}
export function safeFooterUrl(value, email = false) {
  if (typeof value !== 'string' || /[\s\\]/.test(value)) return null
  if (email) return /^[^@<>]+@[^@<>]+\.[^@<>]+$/.test(value) ? `mailto:${value}` : null
  if (/^\/w\/[a-f0-9-]{36}\/agreement$/.test(value)) return value
  try {
    const url = new URL(value)
    return ['https:', 'http:'].includes(url.protocol) && !url.username && !url.password
      ? url.href
      : null
  } catch {
    return null
  }
}
