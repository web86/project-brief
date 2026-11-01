<script setup>
import { computed, onBeforeUnmount, useId, ref } from 'vue'
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
const hintId = useId()
const noticeOpen = ref(false)
const noticeDismissed = ref(false)
function closeNotice(event) {
  if (event.key === 'Escape') {
    noticeOpen.value = false
    noticeDismissed.value = true
    event.stopPropagation()
  }
}
const hasDescription = computed(() => !!props.source.description?.trim())
const unavailable = computed(() => props.disabled || pending.value || !hasDescription.value)
function openAssistant() {
  if (!unavailable.value) {
    show()
    generate()
  }
}
function requestSuggestion() {
  if (!unavailable.value) generate()
}
function applySuggestion() {
  if (!props.disabled) applyResult()
}
onBeforeUnmount(dispose)
</script>
<template>
  <div class="ai-action-row">
    <span class="ai-trigger">
      <button
        type="button"
        class="ai-button"
        :disabled="disabled || pending"
        :aria-disabled="unavailable"
        :aria-describedby="!hasDescription ? hintId : undefined"
        @click="openAssistant"
      >
        <AppIcon name="sparkles" :size="17" />{{ t('ai.improve') }}
      </button>
      <span v-if="!hasDescription" :id="hintId" class="ai-trigger-hint" role="tooltip">{{
        t('ai.describeFirst')
      }}</span> </span
    ><span class="small muted">{{ t('collaboration.improveHint') }}</span>
    <span
      class="ai-disclosure-control"
      :class="{ dismissed: noticeDismissed }"
      @keydown="closeNotice"
      @mouseenter="noticeDismissed = false"
      @focusin="noticeDismissed = false"
      ><button
        type="button"
        class="icon-button"
        :aria-label="t('ai.dataUsage')"
        :aria-describedby="hintId + '-disclosure'"
        @click="noticeOpen = !noticeOpen"
      >
        <AppIcon name="info" :size="18" /></button
      ><span
        :id="hintId + '-disclosure'"
        role="tooltip"
        class="ai-disclosure-tooltip"
        :class="{ visible: noticeOpen }"
        >{{ t('ai.disclosure') }} {{ t('ai.instruction') }}</span
      ></span
    >
  </div>
  <BaseDialog
    class="ai-dialog"
    :dismiss-on-backdrop="!pending"
    :open="open"
    :title="t(response ? 'ai.ready' : 'ai.title')"
    id="ai-dialog-title"
    @close="close"
  >
    <div class="ai-dialog-body">
      <p v-if="!context.apiMode" class="dialog-note">{{ t('ai.demo') }}</p>
      <template v-else>
        <div v-if="pending" class="ai-working" role="status" aria-live="polite">
          <AppIcon name="sparkles" :size="22" /><span>{{ t('ai.generating') }}</span>
        </div>
        <div v-if="!response" class="ai-data-notice">
          <p class="muted small">{{ t('ai.disclosure') }}</p>
          <details class="ai-data-details">
            <summary>{{ t('ai.dataUsage') }}</summary>
            <p class="muted small">{{ t('ai.dataDetails') }}</p>
          </details>
        </div>
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
            <p v-if="!draft.expectedResult.trim()" class="field-help">{{ t('ai.emptyResult') }}</p>
          </div>
          <div v-if="response.questions.length" class="dialog-note">
            <h3>{{ t('ai.questions') }}</h3>
            <p class="muted small">{{ t('ai.questionsNote') }}</p>
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
    </div>
    <template #footer>
      <p v-if="response" class="ai-save-reminder small">{{ t('ai.saveReminder') }}</p>
      <button type="button" class="button secondary" @click="close">{{ t('ai.close') }}</button>
      <LoadingButton
        v-if="context.apiMode && !response"
        type="button"
        class="button primary"
        :busy="pending"
        :disabled="unavailable"
        @click="requestSuggestion"
        >{{ t('ai.send') }}</LoadingButton
      >
      <button
        v-if="response"
        type="button"
        class="button primary"
        :disabled="!canApply || disabled"
        @click="applySuggestion"
      >
        {{ t('ai.apply') }}
      </button>
    </template>
  </BaseDialog>
</template>

<style scoped>
.ai-action-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px 12px;
  margin-top: 12px;
}
.ai-action-row .ai-button {
  padding: 10px 14px;
  font-size: 0.95rem;
}
.ai-disclosure-control {
  position: relative;
}
.ai-disclosure-tooltip {
  display: none;
  position: absolute;
  z-index: 20;
  bottom: 100%;
  inset-inline-end: 0;
  width: min(300px, 75vw);
  padding: 12px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 8px;
  box-shadow: 0 4px 16px #0002;
  font-size: 0.8rem;
}
.ai-disclosure-control:hover .ai-disclosure-tooltip,
.ai-disclosure-control:focus-within .ai-disclosure-tooltip,
.ai-disclosure-tooltip.visible {
  display: block;
}
</style>

<style scoped>
.ai-disclosure-control.dismissed .ai-disclosure-tooltip {
  display: none !important;
}
</style>
