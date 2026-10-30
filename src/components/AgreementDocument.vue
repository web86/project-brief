<script setup>
import { h, computed } from 'vue'
import { safeAgreementUrl } from '../utils/agreementEditor.js'
const props = defineProps({ bare: Boolean, blocks: { type: Array, default: () => [] } })
const tags = { paragraph: 'p', heading: 'h2', subheading: 'h3', bullet: 'ul', number: 'ol' }
const groups = computed(() => {
  const result = []
  for (const block of props.blocks) {
    const previous = result.at(-1)
    if (['bullet', 'number'].includes(block.type) && previous?.type === block.type)
      previous.items.push(block)
    else result.push({ type: block.type, items: [block] })
  }
  return result
})
const content = (block) =>
  (block.content || []).map((span) => {
    let node = String(span.text || '')
    if (span.italic) node = h('em', node)
    if (span.bold) node = h('strong', node)
    const href = safeAgreementUrl(span.href)
    if (href) node = h('a', { href, target: '_blank', rel: 'noopener noreferrer' }, node)
    return node
  })
const Block = (props) =>
  h(
    (props.bare && { heading: 'h1', subheading: 'h2' }[props.group.type]) ||
      tags[props.group.type] ||
      'p',
    ['bullet', 'number'].includes(props.group.type)
      ? props.group.items.map((block) => h('li', content(block)))
      : content(props.group.items[0]),
  )
</script>
<template>
  <template v-if="bare"
    ><Block v-for="(group, index) in groups" :key="index" :group="group" :bare="bare"
  /></template>
  <div v-else class="agreement-document">
    <Block v-for="(group, index) in groups" :key="index" :group="group" :bare="bare" />
  </div>
</template>
<style>
.agreement-document {
  line-height: 1.75;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.agreement-document h2,
.editor-content h1 {
  font-size: 1.3rem;
  margin: 1.4em 0 0.5em;
}
.agreement-document h3,
.editor-content h2 {
  font-size: 1.1rem;
  margin: 1.2em 0 0.4em;
}
.agreement-document p,
.editor-content p {
  margin: 0.65em 0;
}
.agreement-document ul,
.agreement-document ol,
.editor-content ul,
.editor-content ol {
  padding-inline-start: 1.5em;
  margin: 0.3em 0;
}
.agreement-document a,
.editor-content a {
  color: var(--link);
  text-decoration: underline;
  text-underline-offset: 3px;
  overflow-wrap: anywhere;
}
.agreement-document > :first-child,
.editor-content > :first-child {
  margin-top: 0;
}
@media print {
  .app-topbar,
  .app-footer,
  .agreement-actions,
  .skip-link,
  .toast-viewport {
    display: none !important;
  }
  .legal-page {
    box-shadow: none !important;
    max-width: none !important;
    border: 0 !important;
  }
}
</style>
