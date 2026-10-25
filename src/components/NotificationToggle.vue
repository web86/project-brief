<script setup>
defineProps({ checked: Boolean, disabled: Boolean, label: String })
const emit = defineEmits(['change'])
</script>
<template>
  <label class="notification-toggle"
    ><span>{{ label }}</span
    ><input
      type="checkbox"
      role="switch"
      :checked="checked"
      :disabled="disabled"
      @change="emit('change', $event)" /><span class="notification-switch" aria-hidden="true"
  /></label>
</template>
