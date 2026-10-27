<script setup>
import BaseDialog from './BaseDialog.vue'
import SecurityConfirmation from './SecurityConfirmation.vue'
import { stepUpRequest } from '../services/stepUp.js'
import { t } from '../i18n/index.js'
</script>
<template>
  <BaseDialog
    :open="!!stepUpRequest"
    :title="t('security.confirm')"
    id="security-step-up-title"
    @close="stepUpRequest?.(false)"
  >
    <SecurityConfirmation v-if="stepUpRequest" @confirmed="stepUpRequest?.(true)" />
  </BaseDialog>
</template>
