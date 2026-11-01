import { plainDescriptionBlocks } from './taskRichText.js'
import { safeAgreementUrl } from './agreementEditor.js'
export function agentMessageBlocks(text) {
  return plainDescriptionBlocks(text).map((block) => {
    const value = block.content[0].text,
      content = []
    let offset = 0
    for (const match of value.matchAll(
      /\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)|(https?:\/\/[^\s<>"']+)/g,
    )) {
      if (match.index > offset) content.push({ text: value.slice(offset, match.index) })
      const href = safeAgreementUrl(match[2] || match[3])
      content.push(href ? { text: match[1] || match[3], href } : { text: match[0] })
      offset = match.index + match[0].length
    }
    if (offset < value.length) content.push({ text: value.slice(offset) })
    return { ...block, content }
  })
}
