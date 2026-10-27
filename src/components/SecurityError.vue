<script setup>
import { t, formatApiError } from '../i18n/index.js'
defineProps({ error: Object })
</script>
<template>
  <div v-if="error" class="field-error" role="alert">
    <p>{{ formatApiError(error) }}</p>
    <p v-if="error.credentialCreated">{{ t('security.passkeyRetryHelp') }}</p>
    <small v-if="error.diagnosticId">
      {{ t('security.diagnostic') }} <code>{{ error.code }} / {{ error.diagnosticId }}</code>
    </small>
  </div>
</template>
<style scoped>
code {
  overflow-wrap: anywhere;
}
</style>
