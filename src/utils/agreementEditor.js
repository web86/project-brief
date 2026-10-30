export function safeAgreementUrl(value) {
  if (typeof value !== 'string' || value.length > 2048 || /[\s\\\u0000-\u001f\u007f]/.test(value))
    return null
  if (/^mailto:/i.test(value)) {
    const email = value.slice(7)
    return /^[a-z0-9.!$&'*+\/=_`{|}~-]+@[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/i.test(email)
      ? `mailto:${email}`
      : null
  }
  if (!/^https?:\/\/[^/]/i.test(value)) return null
  try {
    const url = new URL(value)
    return url.hostname && !url.username && !url.password ? url.href : null
  } catch {
    return null
  }
}

// Only text and a fixed set of formatting marks cross the API boundary.
export function readAgreementEditor(root) {
  const blocks = []
  const types = {
    P: 'paragraph',
    DIV: 'paragraph',
    H1: 'heading',
    H2: 'subheading',
    H3: 'subheading',
  }
  function spans(node, marks = {}) {
    if (node.nodeType === 3) return [{ text: node.textContent, ...marks }]
    if (node.nodeType !== 1 || ['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT'].includes(node.tagName))
      return []
    if (node.tagName === 'BR') return [{ text: '\n', ...marks }]
    const next = { ...marks }
    if (['B', 'STRONG'].includes(node.tagName)) next.bold = true
    if (['I', 'EM'].includes(node.tagName)) next.italic = true
    if (node.tagName === 'A') {
      const href = safeAgreementUrl(node.getAttribute('href'))
      if (href) next.href = href
      else delete next.href
    }
    return [...node.childNodes].flatMap((child) => spans(child, next))
  }
  const blockSelector = 'p,div,h1,h2,h3,ul,ol'
  function append(node, fallback = 'paragraph') {
    if (node.nodeType === 3) {
      blocks.push({ type: fallback, content: spans(node) })
      return
    }
    if (node.nodeType !== 1 || ['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT'].includes(node.tagName))
      return
    if (['UL', 'OL'].includes(node.tagName)) {
      for (const item of node.children)
        if (item.tagName === 'LI')
          blocks.push({ type: node.tagName === 'UL' ? 'bullet' : 'number', content: spans(item) })
      return
    }
    const type = types[node.tagName] || fallback
    let content = []
    const flush = () => {
      if (content.length) blocks.push({ type, content })
      content = []
    }
    // Native contenteditable can nest a list inside a heading after replacing a template.
    for (const child of node.childNodes) {
      if (
        types[child.tagName] ||
        ['UL', 'OL'].includes(child.tagName) ||
        child.querySelector?.(blockSelector)
      ) {
        flush()
        append(child, type)
      } else content.push(...spans(child))
    }
    flush()
  }
  for (const node of root.childNodes) append(node)
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
