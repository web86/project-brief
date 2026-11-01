<script setup>
import { ref } from 'vue'
import AgreementEditor from './AgreementEditor.vue'
import { descriptionBlocks, blocksDescription } from '../utils/taskRichText.js'
const props = defineProps({ text: String, html: String, disabled: Boolean, invalid: Boolean })
const emit = defineEmits(['change'])
const blocks = ref(descriptionBlocks(props.html, props.text))
function update(value) {
  blocks.value = value
  emit('change', blocksDescription(value))
}
</script>
<template>
  <AgreementEditor
    :model-value="blocks"
    compact
    editor-id="idea-description"
    :disabled="disabled"
    :invalid="invalid"
    @update:model-value="update"
  />
</template>
