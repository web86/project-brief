<script setup>
import { t } from '../i18n/index.js'

import { ref } from 'vue'
import { ideaAssistant } from '../services/ideaAssistant'
import BaseDialog from './BaseDialog.vue'
import AppIcon from './AppIcon.vue'
const props = defineProps({ description: { type: String, default: '' } })
const open = ref(false)
const pending = ref(false)
const response = ref(null)
async function help() {
  pending.value = true
  try {
    const result = await ideaAssistant.help(props.description)
    response.value = result
    open.value = true
  } finally {
    pending.value = false
  }
}
</script>
<template>
  <button type="button" class="ai-button" :disabled="pending" @click="help">
    <AppIcon name="sparkles" :size="16" />{{ t('ui.helpShapeTheIdea') }}
  </button>
  <BaseDialog
    :open="open"
    :title="t('ui.helpWithYourIdea')"
    id="ai-dialog-title"
    @close="open = false"
    ><span class="dialog-illustration"><AppIcon name="sparkles" :size="32" /></span>
    <p>{{ response?.messageKey ? t(response.messageKey) : response?.message }}</p>
    <div class="dialog-note">
      <strong>{{ t('ui.whatIfThereAreSeveralChanges') }}</strong>
      <p>{{ t('ui.theAssistantWillBeAbleToSuggestSplitting') }}</p>
    </div>
    <span class="muted small">{{ t('ui.thisIsADemoYourDescriptionIsNot') }}</span
    ><template #footer
      ><button type="button" class="button primary" @click="open = false">
        {{ t('ui.gotIt') }}
      </button></template
    ></BaseDialog
  >
</template>
