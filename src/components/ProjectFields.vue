<script setup>
defineProps({ modelValue: Object, errors: { type: Object, default: () => ({}) } })
const emit = defineEmits(['update:modelValue'])
const update = (key, value, model) => emit('update:modelValue', { ...model, [key]: value })
const fields = [
  { key: 'title', label: 'Название проекта', type: 'text', required: true, max: 160 },
  {
    key: 'website',
    label: 'Адрес сайта',
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
      :id="`project-${field.key}`"
      :value="modelValue[field.key]"
      :type="field.type"
      :required="field.required"
      :maxlength="field.max"
      :placeholder="field.placeholder"
      :aria-invalid="!!errors[field.key]"
      :aria-describedby="errors[field.key] ? `project-${field.key}-error` : undefined"
      @input="update(field.key, $event.target.value, modelValue)"
    />
    <p v-if="errors[field.key]" :id="`project-${field.key}-error`" class="field-error">
      {{ errors[field.key].join(' ') }}
    </p>
  </div>
  <div class="field">
    <label for="project-currency">Валюта</label
    ><select
      id="project-currency"
      :value="modelValue.currency"
      @change="update('currency', $event.target.value, modelValue)"
    >
      <option v-for="code in ['RUB', 'USD', 'EUR', 'TRY']" :key="code">{{ code }}</option>
    </select>
  </div>
</template>
