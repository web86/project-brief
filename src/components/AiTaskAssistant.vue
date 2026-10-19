<script setup>
import { onBeforeUnmount } from 'vue'
import { t, formatApiError } from '../i18n/index.js'
import { useIdeaAssistant } from '../services/ideaAssistant.js'
import BaseDialog from './BaseDialog.vue'
import LoadingButton from './LoadingButton.vue'
import AppIcon from './AppIcon.vue'

const props = defineProps({
  source: { type: Object, required: true },
  context: { type: Object, required: true },
  disabled: Boolean,
})
const emit = defineEmits(['apply'])
const {
  open,
  pending,
  response,
  draft,
  error,
  stale,
  hasText,
  canApply,
  show,
  close,
  generate,
  applyResult,
  dispose,
} = useIdeaAssistant({
  source: () => props.source,
  context: () => props.context,
  apply: (values) => emit('apply', values),
})
onBeforeUnmount(dispose)
</script>
<template>
  <button type="button" class="ai-button" :disabled="disabled || pending || !hasText" @click="show">
    <AppIcon name="sparkles" :size="16" />{{ t('ai.improve') }}
  </button>
  <BaseDialog :open="open" :title="t('ai.title')" id="ai-dialog-title" @close="close">
    <p v-if="!context.apiMode" class="dialog-note">{{ t('ai.demo') }}</p>
    <template v-else>
      <p v-if="!response" class="dialog-note">{{ t('ai.disclosure') }}</p>
      <p v-if="pending" class="muted" role="status" aria-live="polite">
        {{ t('ai.generating') }}
      </p>
      <p v-if="stale" class="field-error" role="alert">{{ t('ai.sourceChanged') }}</p>
      <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
      <div v-if="draft" class="ai-proposal">
        <p class="muted small">{{ t('ai.review') }}</p>
        <div class="field">
          <label for="ai-title">{{ t('ai.taskTitle') }}</label>
          <input id="ai-title" v-model="draft.title" maxlength="160" @keydown.enter.prevent />
        </div>
        <div class="field">
          <label for="ai-description">{{ t('ai.description') }}</label>
          <textarea id="ai-description" v-model="draft.description" rows="6" maxlength="10000" />
        </div>
        <div class="field">
          <label for="ai-result">{{ t('ai.expectedResult') }}</label>
          <textarea id="ai-result" v-model="draft.expectedResult" rows="3" maxlength="10000" />
        </div>
        <div v-if="response.questions.length" class="dialog-note">
          <h3>{{ t('ai.questions') }}</h3>
          <ul>
            <li v-for="(question, index) in response.questions" :key="index">{{ question }}</li>
          </ul>
        </div>
        <div v-if="response.suggestedIdeas.length" class="ai-suggestions">
          <h3>{{ t('ai.split') }}</h3>
          <p class="muted small">{{ t('ai.splitNote') }}</p>
          <article
            v-for="(idea, index) in response.suggestedIdeas"
            :key="index"
            class="dialog-note"
          >
            <h4>{{ idea.title }}</h4>
            <p class="preserve-lines">{{ idea.description }}</p>
            <p v-if="idea.expectedResult" class="preserve-lines">
              <strong>{{ t('ai.expectedResult') }}:</strong> {{ idea.expectedResult }}
            </p>
          </article>
        </div>
      </div>
    </template>
    <template #footer>
      <button type="button" class="button secondary" @click="close">{{ t('ui.cancel') }}</button>
      <LoadingButton
        v-if="context.apiMode && !response"
        type="button"
        class="button primary"
        :busy="pending"
        :disabled="pending || !hasText || disabled"
        @click="generate"
        >{{ t('ai.send') }}</LoadingButton
      >
      <button
        v-if="response"
        type="button"
        class="button primary"
        :disabled="!canApply || disabled"
        @click="applyResult"
      >
        {{ t('ai.apply') }}
      </button>
    </template>
  </BaseDialog>
</template>
