<script setup>
import { ref } from 'vue'
import AgreementDocument from './AgreementDocument.vue'
import { readAgreementEditor } from '../utils/agreementEditor.js'
import { t } from '../i18n/index.js'
const props = defineProps({ modelValue: Array, disabled: Boolean })
const emit = defineEmits(['update:modelValue'])
const editor = ref(null)
const initial = props.modelValue || []
function update() {
  emit('update:modelValue', readAgreementEditor(editor.value))
}
function command(name, value) {
  editor.value.focus()
  document.execCommand(name, false, value)
  update()
}
function paste(event) {
  event.preventDefault()
  document.execCommand('insertText', false, event.clipboardData.getData('text/plain'))
  update()
}
</script>
<template>
  <div class="agreement-editor">
    <div class="editor-tools" role="group" :aria-label="t('agreement.formatting')">
      <button
        v-for="tool in [
          ['paragraph', 'formatBlock', 'p'],
          ['heading', 'formatBlock', 'h2'],
          ['subheading', 'formatBlock', 'h3'],
          ['bold', 'bold'],
          ['italic', 'italic'],
          ['bullet', 'insertUnorderedList'],
          ['number', 'insertOrderedList'],
        ]"
        :key="tool[0]"
        type="button"
        class="button secondary"
        :disabled="disabled"
        @mousedown.prevent
        @click="command(tool[1], tool[2])"
      >
        {{ t('agreement.' + tool[0]) }}
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
    >
      <AgreementDocument bare :blocks="initial" />
    </div>
  </div>
</template>
<style scoped>
.agreement-editor {
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
}
.editor-tools {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 10px;
  border-bottom: 1px solid var(--border);
}
.editor-tools .button {
  min-height: 32px;
  padding: 4px 9px;
  font-size: 0.8rem;
}
.editor-content {
  min-height: 240px;
  max-height: 460px;
  overflow: auto;
  padding: 12px 18px;
}
</style>
