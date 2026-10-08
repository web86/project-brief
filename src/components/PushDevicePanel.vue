<script setup>
import { t } from '../i18n/index.js'
import LoadingButton from './LoadingButton.vue'
defineProps({ state: { type: String, required: true }, busy: Boolean, error: Boolean })
defineEmits(['enable', 'disable', 'retry'])
</script>
<template>
  <p role="status">{{ t(`notifications.device.${state}`) }}</p>
  <p class="muted">{{ t('notifications.deviceHint') }}</p>
  <p v-if="state === 'unsupported'" class="muted">{{ t('notifications.installHint') }}</p>
  <p v-if="error" class="field-error" role="alert">{{ t('notifications.deviceError') }}</p>
  <LoadingButton
    v-if="['default', 'disabled', 'ownership'].includes(state)"
    :busy="busy"
    :disabled="busy"
    class="button primary"
    @click="$emit('enable')"
    >{{ t('notifications.enable') }}</LoadingButton
  >
  <LoadingButton
    v-if="state === 'enabled'"
    :busy="busy"
    :disabled="busy"
    class="button secondary"
    @click="$emit('disable')"
    >{{ t('notifications.disable') }}</LoadingButton
  >
  <button
    v-if="error"
    type="button"
    class="button secondary"
    :disabled="busy"
    @click="$emit('retry')"
  >
    {{ t('notifications.retry') }}
  </button>
</template>
