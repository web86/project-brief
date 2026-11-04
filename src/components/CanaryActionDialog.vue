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
const evidenceKeys = [
  'lifecycle',
  'execution_barriers',
  'rollback',
  'notification_delivery',
  'trial_claim_policy',
]
const evidence = ref(
  Object.fromEntries(
    evidenceKeys.map((key) => [key, { digest: '', checked: false, hashing: false, failed: false }]),
  ),
)
const digestValid = (digest) => /^[a-f0-9]{64}$/i.test(digest.trim())
const evidenceReady = computed(() =>
  Object.values(evidence.value).every(
    ({ digest, checked, hashing }) => digestValid(digest) && checked && !hashing,
  ),
)
const email = ref('')
const busy = ref(false)
const error = ref('')
const confirmed = ref(false)
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
    (props.action.kind === 'invite'
      ? !!email.value.trim()
      : props.action.kind === 'approve'
        ? evidenceReady.value
        : confirmed.value && (props.action.kind !== 'admit' || props.remainingSeconds > 0)),
)

function setDigest(row, value) {
  row.digest = value
  row.checked = false
  row.failed = false
}

async function hashFile(row, event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  row.digest = ''
  row.checked = false
  row.failed = false
  row.hashing = true
  try {
    if (file.size > 32 * 1024 * 1024 || !globalThis.crypto?.subtle) throw new Error()
    const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer())
    setDigest(
      row,
      Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join(''),
    )
  } catch {
    row.failed = true
  } finally {
    row.hashing = false
  }
}

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
  const { kind, revision, owners } = props.action
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
    const body =
      kind === 'approve'
        ? {
            claims: owners.map((owner) => owner.claimId),
            evidence: Object.fromEntries(
              evidenceKeys.map((key) => [key, evidence.value[key].digest.trim().toLowerCase()]),
            ),
          }
        : kind === 'admit'
          ? { claimId: owners[0].claimId }
          : {}
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
        cause.code === 'plan_revision_conflict' ? 'revisionConflict' : 'conditionsChanged',
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
      <template v-else-if="action.kind === 'approve'">
        <p>{{ l('approveIntro') }}</p>
        <ul class="canary-review-list">
          <li v-for="owner in action.owners" :key="owner.claimId">
            {{ owner.ownerName || owner.email }} · {{ owner.workspace.name }}
          </li>
        </ul>
        <fieldset v-for="(row, key) in evidence" :key="key" :disabled="busy || refreshRequired">
          <legend>{{ l(key) }}</legend>
          <p>{{ l(row.checked && digestValid(row.digest) ? 'attested' : 'unattested') }}</p>
          <label :for="'canary-digest-' + key">{{ l('digest') }}</label>
          <input
            :id="'canary-digest-' + key"
            :value="row.digest"
            type="text"
            maxlength="64"
            spellcheck="false"
            autocomplete="off"
            :disabled="row.hashing"
            @input="setDigest(row, $event.target.value)"
          />
          <label :for="'canary-file-' + key">{{ l('localFile') }}</label>
          <input
            :id="'canary-file-' + key"
            type="file"
            :disabled="row.hashing"
            @change="hashFile(row, $event)"
          />
          <p v-if="row.failed" role="alert">{{ l('hashFailed') }}</p>
          <label class="canary-check"
            ><input
              v-model="row.checked"
              type="checkbox"
              :disabled="!digestValid(row.digest) || row.hashing"
            />
            {{ l('attest') }}</label
          >
        </fieldset>
      </template>
      <template v-else-if="action.kind === 'admit'">
        <dl class="canary-review">
          <div>
            <dt>{{ l('owner') }}</dt>
            <dd>{{ action.owners[0].ownerName }} · {{ action.owners[0].email }}</dd>
          </div>
          <div>
            <dt>{{ l('workspace') }}</dt>
            <dd>{{ action.owners[0].workspace.name }}</dd>
          </div>
          <div v-for="key in ['studioVersion', 'basicVersion', 'policyVersion']" :key="key">
            <dt>{{ t('onboarding.' + key) }}</dt>
            <dd>{{ action.versions[key] }}</dd>
          </div>
          <div>
            <dt>{{ l('verified') }}</dt>
            <dd>{{ date(action.owners[0].verifiedAt) }}</dd>
          </div>
          <div>
            <dt>{{ l('trialStart') }}</dt>
            <dd>{{ date(action.owners[0].verifiedAt) }}</dd>
          </div>
          <div>
            <dt>{{ l('trialEnd') }}</dt>
            <dd>{{ date(action.owners[0].trialEndsAt) }}</dd>
          </div>
          <div>
            <dt>{{ l('remaining') }}</dt>
            <dd>{{ duration }}</dd>
          </div>
        </dl>
        <p>{{ l('elapsed') }}</p>
      </template>
      <p v-else>{{ l('stopIntro') }}</p>
      <label v-if="['admit', 'stop'].includes(action.kind)" class="canary-check">
        <input v-model="confirmed" type="checkbox" :disabled="busy || refreshRequired" />
        {{ l(action.kind + 'Confirm') }}
      </label>
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
.canary-action-form,
fieldset {
  display: grid;
  gap: 0.75rem;
  min-width: 0;
}
fieldset {
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
}
.canary-action-form input:not([type='checkbox']) {
  min-width: 0;
  width: 100%;
}
.canary-action-buttons,
.canary-check {
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
.canary-review-list {
  padding-inline-start: 1.25rem;
}
dd {
  margin: 0;
  overflow-wrap: anywhere;
}
</style>
