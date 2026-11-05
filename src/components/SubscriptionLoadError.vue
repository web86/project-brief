<script setup>
import { t, formatApiError } from '../i18n/index.js'

defineProps({ error: Error, seconds: Number, retry: Function })
</script>

<template>
  <div v-if="error || seconds" class="subscription-load-error">
    <p v-if="seconds">{{ t('subscriptionUx.retryIn', { seconds }) }}</p>
    <p v-else-if="error?.status !== 429" role="alert">{{ formatApiError(error) }}</p>
    <button
      v-if="retry"
      type="button"
      class="button secondary"
      :disabled="seconds > 0"
      @click="retry"
    >
      {{ t('ui.tryAgain') }}
    </button>
  </div>
</template>

<style scoped>
.subscription-load-error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin-block: 12px;
}
.subscription-load-error p {
  margin: 0;
}
</style>
