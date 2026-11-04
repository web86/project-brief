<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { api } from '../api/client.js'
import { locale, t } from '../i18n/index.js'
import { createPlanRequests } from '../services/planManagement.js'
import BaseDialog from './BaseDialog.vue'
import LoadingButton from './LoadingButton.vue'

const props = defineProps({
  action: { type: Object, required: true },
  remainingSeconds: { type: Number, default: 0 },
})
const emit = defineEmits(['close', 'completed', 'refresh'])
const l = (key, params) => t('canary.' + key, params)
const endpoint = '/api/platform/registration-canary/'
const send = createPlanRequests(api.request)
const email = ref('')
const busy = ref(false)
const error = ref('')
const refreshRequired = ref(false)
const link = ref(null)
const copyStatus = ref('')
const secretField = ref(null)
const deadline = new AbortController()
const date = (value) =>
  new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'long',
  }).format(new Date(value))
const duration = computed(() => {
  const minutes = Math.floor(Math.max(0, props.remainingSeconds) / 60)
  return l('remainingTime', {
    days: Math.floor(minutes / 1440),
    hours: Math.floor(minutes / 60) % 24,
    minutes: minutes % 60,
  })
})
const canSubmit = computed(
  () =>
    !busy.value &&
    !refreshRequired.value &&
    (props.action.kind !== 'invite' || !!email.value.trim()) &&
    (props.action.kind !== 'admit' || props.remainingSeconds > 0),
)

function close() {
  if (!busy.value) emit('close')
}

async function copy() {
  try {
    await navigator.clipboard.writeText(link.value.url)
    copyStatus.value = l('copied')
  } catch {
    secretField.value?.select()
    copyStatus.value = l('copyManually')
  }
}

async function submit() {
  if (!canSubmit.value) return
  const { kind, revision, owner } = props.action
  error.value = ''
  busy.value = true
  try {
    if (kind === 'invite') {
      const result = await api.request(endpoint + kind, {
        method: 'POST',
        headers: { 'Idempotency-Key': crypto.randomUUID() },
        body: { email: email.value.trim() },
        signal: deadline.signal,
      })
      if (!deadline.signal.aborted) link.value = result.data
      return
    }
    const body = kind === 'admit' ? { claimId: owner.claimId } : {}
    await send(endpoint + kind, 'POST', body, revision)
    if (!deadline.signal.aborted) emit('completed', kind)
  } catch (cause) {
    if (deadline.signal.aborted) return
    if (cause.code === 'security_confirmation_cancelled') {
      error.value = t('security.confirmationCancelled')
    } else if (
      kind === 'invite' &&
      (!cause.status ||
        cause.status >= 500 ||
        cause.code === 'canary_registration_link_already_issued')
    ) {
      refreshRequired.value = true
      error.value = l('inviteUncertain')
    } else if (cause.code === 'canary_disabled') {
      refreshRequired.value = true
      error.value = l('envClosed')
    } else if (kind !== 'invite' && [409, 428].includes(cause.status)) {
      refreshRequired.value = true
      error.value = l(
        cause.code === 'plan_revision_conflict'
          ? 'revisionConflict'
          : cause.code === 'canary_limit_reached'
            ? 'limitReached'
            : 'conditionsChanged',
      )
    } else if (cause.status === 429) {
      error.value = t('ui.tooManyRequestsPleaseWaitALittle')
    } else if (kind === 'invite' && [409, 422].includes(cause.status)) {
      error.value = l('inviteEmailUnavailable')
    } else if (cause.status === 403 && cause.code === 'canary_owner_onboarding_required') {
      refreshRequired.value = true
      error.value = l('onboardingRequired')
    } else {
      error.value = l(cause.status === 403 ? 'notAllowed' : 'requestFailed')
    }
  } finally {
    busy.value = false
  }
}

onBeforeUnmount(() => {
  deadline.abort()
  link.value = null
})
</script>

<template>
  <BaseDialog open id="canary-action-title" :title="l(action.kind)" @close="close">
    <div v-if="link" class="canary-action-form">
      <p>{{ l('invitePrivate') }}</p>
      <label for="canary-invite-url">{{ l('inviteUrl') }}</label>
      <input
        id="canary-invite-url"
        ref="secretField"
        :value="link.url"
        readonly
        autocomplete="off"
        spellcheck="false"
      />
      <p>
        {{ l('expires') }} <time :datetime="link.expiresAt">{{ date(link.expiresAt) }}</time>
      </p>
      <p v-if="copyStatus" role="status">{{ copyStatus }}</p>
      <div class="canary-action-buttons">
        <button type="button" class="button primary" @click="copy">{{ l('copy') }}</button>
        <button type="button" class="button secondary" @click="close">
          {{ t('ui.closeDialog') }}
        </button>
      </div>
    </div>
    <form v-else class="canary-action-form" @submit.prevent="submit">
      <template v-if="action.kind === 'invite'">
        <p>{{ l('inviteIntro') }}</p>
        <label for="canary-email">{{ l('email') }}</label>
        <input
          id="canary-email"
          v-model="email"
          type="email"
          required
          maxlength="254"
          autocomplete="off"
          :disabled="busy || refreshRequired"
        />
      </template>
      <template v-else-if="action.kind === 'admit'">
        <dl class="canary-review">
          <div>
            <dt>{{ l('owner') }}</dt>
            <dd>{{ action.owner.ownerName }} · {{ action.owner.email }}</dd>
          </div>
          <div>
            <dt>{{ l('workspace') }}</dt>
            <dd>{{ action.owner.workspace.name }}</dd>
          </div>
          <div>
            <dt>{{ l('trialStart') }}</dt>
            <dd>{{ date(action.owner.verifiedAt) }}</dd>
          </div>
          <div>
            <dt>{{ l('trialEnd') }}</dt>
            <dd>{{ date(action.owner.trialEndsAt) }}</dd>
          </div>
          <div>
            <dt>{{ l('remaining') }}</dt>
            <dd>{{ duration }}</dd>
          </div>
        </dl>
        <p>{{ l('elapsed') }}</p>
      </template>
      <p v-else>{{ l(action.kind + 'Intro') }}</p>
      <p v-if="error" role="alert">{{ error }}</p>
      <div class="canary-action-buttons">
        <button
          v-if="refreshRequired && action.kind !== 'invite'"
          type="button"
          class="button primary"
          @click="emit('refresh')"
        >
          {{ t('onboarding.refresh') }}
        </button>
        <LoadingButton
          v-else
          type="submit"
          class="button primary"
          :busy="busy"
          :disabled="!canSubmit"
          >{{ l(action.kind === 'invite' ? 'createInvite' : action.kind) }}</LoadingButton
        >
        <button type="button" class="button secondary" :disabled="busy" @click="close">
          {{ t('account.cancel') }}
        </button>
      </div>
    </form>
  </BaseDialog>
</template>

<style scoped>
.canary-action-form {
  display: grid;
  gap: 0.75rem;
  min-width: 0;
}
.canary-action-form input:not([type='checkbox']) {
  min-width: 0;
  width: 100%;
}
.canary-action-buttons {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.canary-action-buttons {
  flex-wrap: wrap;
}
.canary-review > div {
  padding: 0.4rem 0;
  border-bottom: 1px solid var(--border);
}
dd {
  margin: 0;
  overflow-wrap: anywhere;
}
</style>
