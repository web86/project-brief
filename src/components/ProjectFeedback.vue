<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { useProjectStore } from '../stores/project.js'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import BaseDialog from './BaseDialog.vue'
import LoadingButton from './LoadingButton.vue'
import AppIcon from './AppIcon.vue'
const store = useProjectStore()
const state = ref(null),
  open = ref(false),
  rating = ref(0),
  text = ref(''),
  busy = ref(false),
  error = ref(null)
const root = computed(() => `/api/client/project/${store.project.id}/feedback`)
let revision = 0
onBeforeUnmount(() => revision++)
function openFeedback() {
  open.value = true
  error.value = null
}
watch(
  () => [store.project.id, store.tasks.map((task) => task.id + ':' + task.status).join('|')],
  async () => {
    const current = ++revision
    const path = root.value
    try {
      const result = await api.request(path)
      if (current !== revision) return
      state.value = result
      if (!result.eligible || result.submitted) open.value = false
      if (
        result.eligible &&
        result.requested &&
        !result.shown &&
        !result.dismissed &&
        !result.submitted
      ) {
        const claimed = await api.request(path + '/invitation', {
          method: 'POST',
          body: { action: 'claim' },
        })
        if (current !== revision) return
        state.value = claimed
        open.value = claimed.autoOpen
      }
    } catch (cause) {
      if (current === revision) error.value = cause
    }
  },
  { immediate: true },
)
async function close() {
  if (busy.value) return
  open.value = false
  const current = revision
  try {
    const result = await api.request(root.value + '/invitation', {
      method: 'POST',
      body: { action: 'dismiss' },
    })
    if (current === revision) state.value = result
  } catch (cause) {
    if (current === revision) error.value = cause
  }
}
async function submit() {
  if (busy.value || !rating.value) return
  busy.value = true
  error.value = null
  const current = revision
  try {
    await api.request(root.value, {
      method: 'POST',
      body: { rating: rating.value, text: text.value },
    })
    if (current !== revision) return
    state.value = { ...state.value, submitted: true }
    open.value = false
    toast.success(() => t('feedback.saved'))
  } catch (cause) {
    if (current === revision) error.value = cause
  } finally {
    busy.value = false
  }
}
</script>
<template>
  <div class="project-feedback">
    <button
      v-if="state?.eligible && !state.submitted"
      type="button"
      class="text-button feedback-link"
      @click="openFeedback"
    >
      <AppIcon name="star" :size="16" />{{ t('feedback.leave') }}
    </button>
    <span v-else-if="state?.submitted" class="small feedback-link"
      ><AppIcon name="check" :size="16" />{{ t('feedback.submitted') }}</span
    >
    <p v-if="error && !open" class="small field-error" role="alert">{{ formatApiError(error) }}</p>
    <BaseDialog
      :open="open"
      :title="t('feedback.title')"
      id="project-feedback-title"
      @close="close"
    >
      <form id="project-feedback-form" class="feedback-form" @submit.prevent="submit">
        <p>{{ t('feedback.thanks') }}</p>
        <fieldset class="feedback-rating" :disabled="busy">
          <legend>{{ t('feedback.rating') }}</legend>
          <div class="rating-stars">
            <label
              v-for="value in 5"
              :key="value"
              class="rating-choice"
              :class="{ selected: value <= rating }"
            >
              <input
                v-model="rating"
                type="radio"
                name="feedback-rating"
                :value="value"
                :aria-label="t('feedback.stars', { count: value })"
                required
              />
              <AppIcon name="star" :size="30" />
            </label>
          </div>
        </fieldset>
        <div class="field">
          <label for="feedback-text">{{ t('feedback.text') }}</label>
          <textarea id="feedback-text" v-model="text" rows="4" maxlength="5000" :disabled="busy" />
        </div>
        <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
      </form>
      <template #footer>
        <button type="button" class="button secondary" :disabled="busy" @click="close">
          {{ t('feedback.later') }}
        </button>
        <LoadingButton
          type="submit"
          form="project-feedback-form"
          class="button primary"
          :busy="busy"
          :disabled="busy || !rating"
          >{{ t('feedback.send') }}</LoadingButton
        >
      </template>
    </BaseDialog>
  </div>
</template>
