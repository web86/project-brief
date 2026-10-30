<script setup>
import { h } from 'vue'
defineProps({ bare: Boolean, blocks: { type: Array, default: () => [] } })
const tags = { paragraph: 'p', heading: 'h2', subheading: 'h3', bullet: 'ul', number: 'ol' }
const Block = (props) => {
  const block = props.block
  const content = (block.content || []).map((span) => {
    let node = String(span.text || '')
    if (span.italic) node = h('em', node)
    if (span.bold) node = h('strong', node)
    return node
  })
  return h(
    tags[block.type] || 'p',
    ['bullet', 'number'].includes(block.type) ? [h('li', content)] : content,
  )
}
</script>
<template>
  <template v-if="bare"
    ><Block v-for="(block, index) in blocks" :key="index" :block="block"
  /></template>
  <div v-else class="agreement-document">
    <Block v-for="(block, index) in blocks" :key="index" :block="block" />
  </div>
</template>
<style>
.agreement-document {
  line-height: 1.75;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.agreement-document h2 {
  font-size: 1.3rem;
  margin: 1.4em 0 0.5em;
}
.agreement-document h3 {
  font-size: 1.1rem;
  margin: 1.2em 0 0.4em;
}
.agreement-document p {
  margin: 0.65em 0;
}
.agreement-document ul,
.agreement-document ol {
  padding-inline-start: 1.5em;
  margin: 0.3em 0;
}
.agreement-document > :first-child {
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
