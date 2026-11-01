import { readAgreementEditor, safeAgreementUrl } from './agreementEditor.js'
const escape = (value) =>
  String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
export function plainDescriptionBlocks(value) {
  return String(value || '')
    .split('\n')
    .map((line) => ({
      type: /^\s*[-•]\s/.test(line)
        ? 'bullet'
        : /^\s*\d+[.)]\s/.test(line)
          ? 'number'
          : 'paragraph',
      content: [{ text: line.replace(/^\s*(?:[-•]|\d+[.)])\s/, '') }],
    }))
}
export function descriptionBlocks(html, text = '') {
  if (!html || typeof DOMParser === 'undefined') return plainDescriptionBlocks(text)
  return readAgreementEditor(new DOMParser().parseFromString(html, 'text/html').body, {
    preserveEmpty: true,
  })
}
export function blocksDescription(blocks) {
  let number = 0
  const text = blocks
    .map((block) => {
      number = block.type === 'number' ? number + 1 : 0
      return (
        (block.type === 'bullet' ? '• ' : number ? `${number}. ` : '') +
        block.content
          .map(
            (span) =>
              span.text +
              (safeAgreementUrl(span.href) && span.href !== span.text ? ` (${span.href})` : ''),
          )
          .join('')
      )
    })
    .join('\n')
  let html = '',
    list = ''
  for (const block of blocks) {
    const type = block.type === 'bullet' ? 'ul' : block.type === 'number' ? 'ol' : ''
    if (type !== list) {
      if (list) html += `</${list}>`
      if (type) html += `<${type}>`
      list = type
    }
    let content = block.content
      .map((span) => {
        let value = escape(span.text).replaceAll('\n', '<br>')
        if (span.bold) value = `<strong>${value}</strong>`
        if (span.italic) value = `<em>${value}</em>`
        const href = safeAgreementUrl(span.href)
        return href ? `<a href="${escape(href)}">${value}</a>` : value
      })
      .join('')
    html += type ? `<li>${content}</li>` : `<p>${content || '<br>'}</p>`
  }
  if (list) html += `</${list}>`
  return { text, html }
}
export function mergeDescriptionText(value, previous) {
  const blocks = plainDescriptionBlocks(value)
  return blocks.map(
    (block) =>
      previous.find(
        (old) =>
          old.type === block.type &&
          old.content.map((span) => span.text).join('') === block.content[0].text,
      ) || block,
  )
}
