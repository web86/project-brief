<script setup>
import { ref } from 'vue'
import { ideaAssistant } from '../services/ideaAssistant'
import BaseDialog from './BaseDialog.vue'
import AppIcon from './AppIcon.vue'
const props = defineProps({ description: { type: String, default: '' } })
const open = ref(false)
const pending = ref(false)
const message = ref('')
async function help() {
  pending.value = true
  try {
    const result = await ideaAssistant.help(props.description)
    message.value = result.message
    open.value = true
  } finally { pending.value = false }
}
</script>
<template>
  <button type="button" class="ai-button" :disabled="pending" @click="help"><AppIcon name="sparkles" :size="16" />Помочь оформить идею</button>
  <BaseDialog :open="open" title="Помощь с вашей идеей" id="ai-dialog-title" @close="open = false"><span class="dialog-illustration"><AppIcon name="sparkles" :size="32" /></span><p>{{ message }}</p><div class="dialog-note"><strong>А если изменений несколько?</strong><p>Помощник сможет предложить разделить большую идею на несколько небольших. Эта возможность появится на следующем этапе.</p></div><span class="muted small">Сейчас это демонстрация. Ваше описание никуда не отправляется.</span><template #footer><button type="button" class="button primary" @click="open = false">Понятно</button></template></BaseDialog>
</template>
