<script setup>
import { t } from '../i18n/index.js'

defineProps({ modelValue: Object, errors: { type: Object, default: () => ({}) } })
const emit = defineEmits(['update:modelValue'])
const update = (key, value, model, event) => {
  event?.target.setCustomValidity('')
  emit('update:modelValue', { ...model, [key]: value })
}
const fields = [
  {
    key: 'title',
    get label() {
      return t('ui.projectName')
    },
    type: 'text',
    required: true,
    max: 160,
  },
  {
    key: 'website',
    get label() {
      return t('ui.websiteAddress')
    },
    type: 'url',
    placeholder: 'https://example.com',
    max: 2048,
  },
]
</script>
<template>
  <div v-for="field in fields" :key="field.key" class="field">
    <label :for="`project-${field.key}`">{{ field.label }}{{ field.required ? ' *' : '' }}</label>
    <input
      @invalid="$event.target.setCustomValidity(t('flow.fieldError'))"
      :id="`project-${field.key}`"
      :value="modelValue[field.key]"
      :type="field.type"
      :required="field.required"
      :maxlength="field.max"
      :placeholder="field.placeholder"
      :aria-invalid="!!errors[field.key]"
      :aria-describedby="errors[field.key] ? `project-${field.key}-error` : undefined"
      @input="update(field.key, $event.target.value, modelValue, $event)"
    />
    <p v-if="errors[field.key]" :id="`project-${field.key}-error`" class="field-error">
      {{ t('flow.fieldError') }}
    </p>
  </div>
  <div class="field">
    <label for="project-currency">{{ t('ui.currency') }}</label
    ><select
      id="project-currency"
      :value="modelValue.currency"
      :aria-invalid="!!errors.currency"
      :aria-describedby="errors.currency ? 'project-currency-error' : undefined"
      @change="update('currency', $event.target.value, modelValue)"
    >
      <option v-for="code in ['RUB', 'USD', 'EUR', 'TRY']" :key="code">{{ code }}</option>
    </select>
    <p v-if="errors.currency" id="project-currency-error" class="field-error">
      {{ t('flow.fieldError') }}
    </p>
  </div>
</template>
