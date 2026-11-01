<script setup>
import { computed, ref } from 'vue'
import { t } from '../i18n/index.js'
import { formatDate } from '../constants/project.js'
import { agentTimestamp } from '../services/agentPresentation.js'
import { agentMessageBlocks } from '../utils/agentChat.js'
import AgreementDocument from './AgreementDocument.vue'
import AttachmentList from './AttachmentList.vue'
const props = defineProps({ message: Object, name: String, canShare: Boolean })
const emit = defineEmits(['share'])
const expanded = ref(false)
const long = computed(
  () => props.message.text?.length > 500 || props.message.text?.split('\n').length > 8,
)
const blocks = computed(() => agentMessageBlocks(props.message.text))
function share(audience) {
  emit('share', {
    audience,
    comment: {
      id: props.message.commentId,
      text: props.message.text,
      attachments: props.message.attachments || [],
      teamOnly: true,
      agentReport: props.message.report,
    },
  })
}
</script>
<template>
  <article class="agent-dialogue-message" :class="message.author" :data-message-id="message.id">
    <header>
      <strong>{{
        message.author === 'agent'
          ? name || message.agentName || t('agentUx.agent')
          : t('collaboration.author.human')
      }}</strong
      ><time>{{ formatDate(agentTimestamp(message.createdAt)) }}</time>
    </header>
    <div class="agent-message-text" :class="{ 'is-collapsed': long && !expanded }">
      <AgreementDocument :blocks="blocks" bare />
    </div>
    <button v-if="long" class="text-button" :aria-expanded="expanded" @click="expanded = !expanded">
      {{ t(expanded ? 'agentUx.collapse' : 'agentUx.expand') }}
    </button>
    <AttachmentList v-if="message.attachments?.length" :attachments="message.attachments" compact />
    <div v-if="message.report" class="agent-message-links">
      <a
        v-if="message.report.pullRequestUrl"
        :href="message.report.pullRequestUrl"
        target="_blank"
        rel="noopener noreferrer"
        >{{ t('agents.pullRequest') }}</a
      >
      <a
        v-if="message.report.resultUrl"
        :href="message.report.resultUrl"
        target="_blank"
        rel="noopener noreferrer"
        >{{ t('agents.result') }}</a
      >
    </div>
    <p v-if="message.teamOnly === false" class="muted">{{ t('agentUx.publicHistory') }}</p>
    <div
      v-if="
        canShare && message.teamOnly !== false && message.author === 'agent' && message.commentId
      "
      class="agent-message-actions"
    >
      <button class="text-button" @click="share('team')">{{ t('agentUx.shareTeam') }}</button
      ><button class="text-button" @click="share('client')">{{ t('agents.shareClient') }}</button>
    </div>
  </article>
</template>
<style scoped>
.agent-dialogue-message {
  padding: 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  min-width: 0;
  overflow-wrap: anywhere;
}
.agent-dialogue-message.human {
  background: var(--surface-secondary, var(--surface));
}
header {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: baseline;
  gap: 6px 12px;
  margin-bottom: 8px;
}
time {
  color: var(--muted);
  font-size: 0.75rem;
}
.agent-message-text.is-collapsed {
  max-height: 8.5em;
  overflow: hidden;
}
.agent-message-text :deep(.agreement-document) {
  font-size: 0.875rem;
  line-height: 1.6;
}
.agent-message-text :deep(p),
.agent-message-text :deep(ul),
.agent-message-text :deep(ol) {
  margin-block: 0 6px;
}
.agent-message-actions,
.agent-message-links {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;
  margin-top: 10px;
  font-size: 0.8rem;
}
</style>
