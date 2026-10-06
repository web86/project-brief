<script setup>
import { t } from '../i18n/index.js'

import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps({
  id: { type: String, required: true },
  modelValue: { type: String, default: '' },
  suggestions: { type: Array, default: () => [] },
  invalid: Boolean,
  describedBy: String,
})
const emit = defineEmits(['update:modelValue'])
const popup = ref(null)
const open = ref(false)
const activeIndex = ref(-1)
const options = computed(() => {
  const query = props.modelValue.trim().toLocaleLowerCase('ru')
  return [...new Set(props.suggestions)]
    .filter((item) => item.toLocaleLowerCase('ru').includes(query))
    .slice(0, 8)
})
const expanded = computed(() => open.value && options.value.length > 0)
const optionId = (index) => `${props.id}-option-${index}`

watch(
  () => props.modelValue,
  () => {
    activeIndex.value = -1
  },
)
function input(event) {
  emit('update:modelValue', event.target.value)
  activeIndex.value = -1
  open.value = true
}
function select(value) {
  emit('update:modelValue', value)
  open.value = false
  activeIndex.value = -1
}
function close() {
  open.value = false
  activeIndex.value = -1
}
async function keydown(event) {
  if (event.isComposing) return
  if (event.key === 'Escape') {
    if (open.value) event.preventDefault()
    close()
  } else if (event.key === 'Tab') {
    close()
  } else if (event.key === 'Enter' && expanded.value && activeIndex.value >= 0) {
    event.preventDefault()
    select(options.value[activeIndex.value])
  } else if (['ArrowDown', 'ArrowUp'].includes(event.key) && options.value.length) {
    event.preventDefault()
    open.value = true
    const direction = event.key === 'ArrowDown' ? 1 : -1
    activeIndex.value =
      activeIndex.value < 0
        ? direction === 1
          ? 0
          : options.value.length - 1
        : (activeIndex.value + direction + options.value.length) % options.value.length
    await nextTick()
    popup.value?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' })
  }
}
</script>

<template>
  <div class="location-input">
    <input
      :id="id"
      :value="modelValue"
      type="text"
      role="combobox"
      autocomplete="off"
      :spellcheck="false"
      required
      :placeholder="t('ui.forExampleHomePageProductPageOrPaste')"
      aria-autocomplete="list"
      aria-haspopup="listbox"
      :aria-expanded="expanded"
      :aria-controls="expanded ? `${id}-options` : undefined"
      :aria-activedescendant="expanded && activeIndex >= 0 ? optionId(activeIndex) : undefined"
      :aria-invalid="invalid"
      :aria-describedby="describedBy"
      @input="input"
      @focus="open = true"
      @blur="close"
      @keydown="keydown"
    />
    <ul
      v-if="expanded"
      :id="`${id}-options`"
      ref="popup"
      class="location-options"
      role="listbox"
      :aria-label="t('ui.websiteLocationSuggestions')"
    >
      <li
        v-for="(option, index) in options"
        :id="optionId(index)"
        :key="option"
        role="option"
        :aria-selected="activeIndex === index"
        :title="option"
        @pointerdown.prevent="select(option)"
        @mouseenter="activeIndex = index"
      >
        {{ option }}
      </li>
    </ul>
  </div>
</template>
