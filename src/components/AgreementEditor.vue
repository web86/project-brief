<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import AgreementDocument from './AgreementDocument.vue'
import AppIcon from './AppIcon.vue'
import BaseDialog from './BaseDialog.vue'
import { readAgreementEditor, safeAgreementUrl } from '../utils/agreementEditor.js'
import { useWorkspaceAppearance } from '../services/workspaceAppearance.js'
import { t } from '../i18n/index.js'
const props = defineProps({ modelValue: Array, disabled: Boolean })
const emit = defineEmits(['update:modelValue'])
const { tokens } = useWorkspaceAppearance()
const editor = ref(null),
  active = ref({}),
  link = ref(null),
  linkText = ref(null)
// Keep native editing DOM and its undo stack stable while v-model receives serialized blocks.
const initial = props.modelValue || []
const tools = [
  ['paragraph', 'paragraph', 'formatBlock', 'p'],
  ['heading', 'heading-1', 'formatBlock', 'h1'],
  ['subheading', 'heading-2', 'formatBlock', 'h2'],
  ['bold', 'bold', 'bold'],
  ['italic', 'italic', 'italic'],
  ['bullet', 'list-bullet', 'insertUnorderedList'],
  ['number', 'list-number', 'insertOrderedList'],
]
let savedRange = null
function currentRange() {
  const selection = window.getSelection()
  if (!selection?.rangeCount) return null
  const range = selection.getRangeAt(0)
  return editor.value?.contains(range.commonAncestorContainer) ? range : null
}
function anchor(range) {
  const closest = (node) => (node?.nodeType === 1 ? node : node?.parentElement)?.closest('a')
  const start = closest(range?.startContainer)
  return start && editor.value.contains(start) && start === closest(range?.endContainer)
    ? start
    : null
}
function selectionChanged() {
  const range = currentRange()
  if (!range) {
    if (!link.value) active.value = {}
    return
  }
  savedRange = range.cloneRange()
  const block = String(document.queryCommandValue('formatBlock')).toLowerCase().replace(/[<>]/g, '')
  active.value = {
    paragraph: ['', 'p', 'div'].includes(block),
    heading: block === 'h1',
    subheading: block === 'h2',
    bold: document.queryCommandState('bold'),
    italic: document.queryCommandState('italic'),
    bullet: document.queryCommandState('insertUnorderedList'),
    number: document.queryCommandState('insertOrderedList'),
    link: !!anchor(range),
  }
}
function restoreSelection() {
  editor.value.focus({ preventScroll: true })
  const range =
    savedRange?.startContainer.isConnected &&
    editor.value.contains(savedRange.commonAncestorContainer)
      ? savedRange.cloneRange()
      : document.createRange()
  if (!savedRange || !editor.value.contains(range.commonAncestorContainer)) {
    range.selectNodeContents(editor.value)
    range.collapse(false)
  }
  const selection = window.getSelection()
  selection.removeAllRanges()
  selection.addRange(range)
  return range
}
function update() {
  emit('update:modelValue', readAgreementEditor(editor.value))
  selectionChanged()
}
function command(name, value) {
  if (props.disabled) return
  restoreSelection()
  document.execCommand(name, false, value)
  update()
}
async function openLink() {
  if (props.disabled) return
  const range = currentRange() || restoreSelection()
  const existing = anchor(range)
  if (existing) range.selectNodeContents(existing)
  savedRange = range.cloneRange()
  link.value = {
    text: range.toString(),
    url: existing?.getAttribute('href') || '',
    editing: !!existing,
    bold: document.queryCommandState('bold'),
    italic: document.queryCommandState('italic'),
    error: '',
  }
  await nextTick()
  linkText.value?.focus()
}
async function closeLink() {
  link.value = null
  await nextTick()
  restoreSelection()
  selectionChanged()
}
async function saveLink() {
  if (props.disabled || !link.value) return
  const href = safeAgreementUrl(link.value.url.trim())
  if (!href || !link.value.text.trim()) {
    link.value.error = t(!href ? 'agreement.linkInvalid' : 'agreement.linkTextRequired')
    return
  }
  const draft = { ...link.value }
  link.value = null
  await nextTick()
  const range = restoreSelection()
  if (!range.collapsed && range.toString() === draft.text) {
    document.execCommand('createLink', false, href)
    // Only fixed attributes are added; native createLink retains the selected formatting and undo.
    for (const item of editor.value.querySelectorAll('a')) {
      item.target = '_blank'
      item.rel = 'noopener noreferrer'
    }
  } else {
    const existing = anchor(range)
    if (existing) range.selectNode(existing)
    const item = document.createElement('a')
    item.href = href
    item.target = '_blank'
    item.rel = 'noopener noreferrer'
    let content = document.createTextNode(draft.text)
    for (const [marked, tag] of [
      [draft.italic, 'em'],
      [draft.bold, 'strong'],
    ]) {
      if (marked) {
        const wrapper = document.createElement(tag)
        wrapper.append(content)
        content = wrapper
      }
    }
    item.append(content)
    document.execCommand('insertHTML', false, item.outerHTML)
  }
  update()
}
function unlink() {
  if (props.disabled) return
  const range = currentRange() || restoreSelection()
  const existing = anchor(range)
  if (existing) range.selectNodeContents(existing)
  savedRange = range.cloneRange()
  command('unlink')
}
function paste(event) {
  event.preventDefault()
  document.execCommand('insertText', false, event.clipboardData.getData('text/plain'))
  update()
}
onMounted(() => document.addEventListener('selectionchange', selectionChanged))
onBeforeUnmount(() => document.removeEventListener('selectionchange', selectionChanged))
</script>
<template>
  <div class="agreement-editor">
    <div class="editor-tools" role="group" :aria-label="t('agreement.formatting')">
      <button
        v-for="tool in tools"
        :key="tool[0]"
        type="button"
        class="icon-button"
        :disabled="disabled"
        :title="t('agreement.' + tool[0])"
        :aria-label="t('agreement.' + tool[0])"
        :aria-pressed="!!active[tool[0]]"
        @mousedown.prevent
        @click="command(tool[2], tool[3])"
      >
        <AppIcon :name="tool[1]" :size="19" />
      </button>
      <button
        type="button"
        class="icon-button"
        :disabled="disabled"
        :title="t('agreement.link')"
        :aria-label="t('agreement.link')"
        :aria-pressed="!!active.link"
        @mousedown.prevent
        @click="openLink"
      >
        <AppIcon name="link" :size="19" />
      </button>
      <button
        type="button"
        class="icon-button"
        :disabled="disabled || !active.link"
        :title="t('agreement.unlink')"
        :aria-label="t('agreement.unlink')"
        @mousedown.prevent
        @click="unlink"
      >
        <AppIcon name="unlink" :size="19" />
      </button>
    </div>
    <div
      ref="editor"
      class="editor-content"
      :contenteditable="!disabled"
      role="textbox"
      aria-multiline="true"
      :aria-label="t('agreement.text')"
      @input="update"
      @paste="paste"
      @drop.prevent
      @keyup="selectionChanged"
      @mouseup="selectionChanged"
    >
      <AgreementDocument bare :blocks="initial" />
    </div>
    <Teleport to="body">
      <BaseDialog
        :open="!!link"
        :title="t(link?.editing ? 'agreement.editLink' : 'agreement.link')"
        id="agreement-link-title"
        class="agreement-link-dialog"
        :style="tokens"
        @close="closeLink"
      >
        <form
          v-if="link"
          id="agreement-link-form"
          class="agreement-link-form"
          @submit.prevent.stop="saveLink"
        >
          <label class="field"
            >{{ t('agreement.linkText') }}<input ref="linkText" v-model="link.text" required
          /></label>
          <label class="field"
            >{{ t('agreement.linkUrl')
            }}<input v-model="link.url" type="text" inputmode="url" autocomplete="url" required
          /></label>
          <p v-if="link.error" class="field-error" role="alert">{{ link.error }}</p>
        </form>
        <template #footer>
          <button type="button" class="button secondary" @click="closeLink">
            {{ t('ui.cancel') }}
          </button>
          <button type="submit" form="agreement-link-form" class="button primary">
            {{ t(link?.editing ? 'account.save' : 'agreement.insertLink') }}
          </button>
        </template>
      </BaseDialog>
    </Teleport>
  </div>
</template>
<style scoped>
.agreement-editor {
  min-width: 0;
  max-width: 100%;
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
}
.editor-tools {
  display: flex;
  flex-wrap: nowrap;
  gap: 4px;
  padding: 8px;
  overflow-x: auto;
  border-bottom: 1px solid var(--border);
}
.editor-tools .icon-button {
  flex: 0 0 36px;
  width: 36px;
  min-width: 36px;
  min-height: 36px;
  padding: 6px;
}
.editor-tools [aria-pressed='true'] {
  background: var(--surface-soft);
  color: var(--link);
  box-shadow: inset 0 0 0 1px var(--link);
}
.editor-content {
  min-height: 240px;
  max-height: 460px;
  overflow: auto;
  padding: 14px 18px;
  line-height: 1.75;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.agreement-link-dialog {
  width: min(460px, calc(100% - 32px));
}
.agreement-link-form {
  display: grid;
  gap: 16px;
}
.agreement-link-form .field {
  margin: 0;
}
@media (max-width: 600px) {
  .editor-tools .icon-button {
    flex-basis: 44px;
    width: 44px;
    min-width: 44px;
    min-height: 44px;
  }
}
</style>
