<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '../api/client.js'
import { locale, t } from '../i18n/index.js'
import AppIcon from '../components/AppIcon.vue'
import CanaryActionDialog from '../components/CanaryActionDialog.vue'
import LoadingButton from '../components/LoadingButton.vue'

const l = (key, params) => t('canary.' + key, params)
const state = ref(null)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const action = ref(null)
const monotonicTime = ref(performance.now())
const deadline = new AbortController()
const management = computed(() => state.value?.management)
const canary = computed(() => state.value?.canary)
let serverOffset = 0
let timer
const serverNow = computed(() => monotonicTime.value + serverOffset)
const canActivate = computed(
  () =>
    canary.value?.admissionEnabled &&
    !canary.value.activationStopped &&
    canary.value.configurationReady &&
    canary.value.remainingActivations > 0 &&
    !!management.value.revision,
)
const remainingSeconds = (owner) =>
  Math.max(0, Math.ceil((Date.parse(owner?.trialEndsAt) - serverNow.value) / 1000))
const date = (value) =>
  value
    ? new Intl.DateTimeFormat(locale.value, {
        dateStyle: 'medium',
        timeStyle: 'long',
      }).format(new Date(value))
    : '—'
const eligible = (owner) => owner.eligible && remainingSeconds(owner) > 0

async function load(page = management.value?.page ?? 1) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const { data } = await api.request('/api/platform/registration-canary/owners?page=' + page, {
      signal: deadline.signal,
    })
    monotonicTime.value = performance.now()
    serverOffset = Date.parse(data.management.serverTime) - monotonicTime.value
    state.value = data
  } catch (cause) {
    if (deadline.signal.aborted) return
    state.value = null
    error.value =
      cause.code === 'security_confirmation_cancelled'
        ? t('security.confirmationCancelled')
        : cause.status === 429
          ? t('ui.tooManyRequestsPleaseWaitALittle')
          : l(cause.status === 403 ? 'notAllowed' : 'requestFailed')
  } finally {
    busy.value = false
  }
}

function open(kind, owner = null) {
  if (busy.value) return
  notice.value = ''
  action.value = { kind, owner, revision: management.value.revision }
}

function refresh() {
  action.value = null
  void load()
}

function completed(kind) {
  notice.value = l(kind + 'Done')
  refresh()
}

function ownerStatus(owner) {
  if (owner.period) return l('periodAssigned')
  if (!owner.emailVerified) return l('emailPending')
  if (owner.windowClosed || (owner.trialEndsAt && remainingSeconds(owner) <= 0))
    return l('windowClosed')
  return l(owner.eligible ? 'readyToAdmit' : 'ownerNotEligible')
}

onMounted(() => {
  timer = setInterval(() => {
    monotonicTime.value = performance.now()
  }, 1000)
  void load()
})
onBeforeUnmount(() => {
  clearInterval(timer)
  deadline.abort()
})
</script>

<template>
  <section class="platform-page registration-diagnostics">
    <header class="canary-toolbar">
      <h1>{{ t('onboarding.diagnostics') }}</h1>
      <LoadingButton class="button secondary" :busy="busy" @click="load()">{{
        t('onboarding.refresh')
      }}</LoadingButton>
    </header>
    <p v-if="error" role="alert">{{ error }}</p>
    <p v-if="notice" role="status">{{ notice }}</p>
    <template v-if="state">
      <section class="surface canary-panel" aria-labelledby="canary-title">
        <header class="canary-toolbar">
          <h2 id="canary-title">{{ l('title') }}</h2>
          <button
            class="button primary"
            type="button"
            :disabled="busy || !management.inviteEnabled"
            @click="open('invite')"
          >
            + {{ l('invite') }}
          </button>
        </header>
        <dl>
          <div>
            <dt>{{ l('activationStatus') }}</dt>
            <dd v-if="!canary.admissionEnabled">{{ l('envClosed') }}</dd>
            <dd v-else-if="canary.activationStopped">{{ l('paused') }}</dd>
            <dd v-else-if="!canary.configurationReady">{{ l('blocked') }}</dd>
            <dd v-else-if="!canary.remainingActivations">{{ l('limitReached') }}</dd>
            <dd v-else>{{ l('slotsRemaining', { count: canary.remainingActivations }) }}</dd>
          </div>
        </dl>
        <div v-if="management.revision" class="canary-actions">
          <button
            v-if="canary.activationStopped"
            class="button secondary"
            type="button"
            :disabled="busy || !canary.admissionEnabled"
            @click="open('resume')"
          >
            {{ l('resume') }}
          </button>
          <button
            v-else
            class="button secondary"
            type="button"
            :disabled="busy"
            @click="open('stop')"
          >
            {{ l('stop') }}
          </button>
        </div>
      </section>

      <section class="surface canary-panel" aria-labelledby="canary-owners-title">
        <h2 id="canary-owners-title">{{ l('owners') }}</h2>
        <p v-if="!management.owners.length">{{ l('noOwners') }}</p>
        <ul v-else class="canary-owners">
          <li v-for="owner in management.owners" :key="owner.id">
            <header class="canary-toolbar">
              <div>
                <h3>{{ owner.ownerName || owner.email || owner.id }}</h3>
                <p v-if="owner.email && owner.ownerName">{{ owner.email }}</p>
              </div>
              <span class="canary-status"
                ><AppIcon
                  :name="owner.period || owner.eligible ? 'check' : 'info'"
                  aria-hidden="true"
                />
                {{ ownerStatus(owner) }}</span
              >
            </header>
            <dl class="canary-owner-data">
              <div>
                <dt>{{ l('workspace') }}</dt>
                <dd>{{ owner.workspace.name || '—' }}</dd>
              </div>
              <div v-if="owner.period">
                <dt>{{ l('periodAssigned') }}</dt>
                <dd>{{ date(owner.period.startsAt) }} → {{ date(owner.period.endsAt) }}</dd>
              </div>
            </dl>
            <div v-if="eligible(owner)" class="canary-actions">
              <button
                class="button primary"
                type="button"
                :disabled="busy || !canActivate"
                @click="open('admit', owner)"
              >
                {{ l('admit') }}
              </button>
            </div>
          </li>
        </ul>
        <nav
          v-if="management.page > 1 || management.hasMore"
          class="canary-actions"
          :aria-label="l('owners')"
        >
          <button
            class="button secondary"
            type="button"
            :disabled="busy || management.page <= 1"
            @click="load(management.page - 1)"
          >
            {{ l('previous') }}
          </button>
          <button
            class="button secondary"
            type="button"
            :disabled="busy || !management.hasMore"
            @click="load(management.page + 1)"
          >
            {{ l('next') }}
          </button>
        </nav>
      </section>

      <details>
        <summary>{{ l('otherDiagnostics') }}</summary>
        <ul v-if="canary.reasonCodes.length" class="canary-reasons">
          <li v-for="reason in canary.reasonCodes" :key="reason">{{ l('reasons.' + reason) }}</li>
        </ul>
        <dl class="canary-summary">
          <div>
            <dt>{{ t('onboarding.canaryConfiguration') }}</dt>
            <dd class="canary-status">
              <AppIcon :name="canary.configurationReady ? 'check' : 'info'" aria-hidden="true" />
              {{ l(canary.configurationReady ? 'ready' : 'blocked') }}
            </dd>
          </div>
          <div v-for="key in ['studioVersion', 'basicVersion', 'policyVersion']" :key="key">
            <dt>{{ t('onboarding.' + key) }}</dt>
            <dd>{{ canary[key] ?? t('onboarding.notPublished') }}</dd>
          </div>
          <div>
            <dt>{{ t('onboarding.emailReady') }}</dt>
            <dd>{{ l(state.emailReady ? 'configured' : 'notConfigured') }}</dd>
          </div>
          <div>
            <dt>{{ t('onboarding.retention') }}</dt>
            <dd>{{ canary.retentionDays ?? l('notConfigured') }}</dd>
          </div>
          <div>
            <dt><code>TRIAL_ACTIVATION_ENABLED</code></dt>
            <dd>{{ l(canary.admissionEnabled ? 'enabled' : 'disabled') }}</dd>
          </div>
          <div>
            <dt>{{ l('admittedOwners') }}</dt>
            <dd>{{ management.admittedOwners }}</dd>
          </div>
          <div>
            <dt>{{ l('allowlistLimit') }}</dt>
            <dd>
              {{ l('limitValue', { count: management.claimLimit, hours: management.windowHours }) }}
            </dd>
          </div>

          <div>
            <dt>{{ t('onboarding.registrationStatus') }}</dt>
            <dd>{{ t('onboarding.' + (state.publicRegistration ? 'enabled' : 'disabled')) }}</dd>
          </div>
          <div v-for="(ready, provider) in state.providers" :key="provider">
            <dt>{{ t('onboarding.' + provider) }}</dt>
            <dd>{{ t('onboarding.' + (ready ? 'yes' : 'no')) }}</dd>
          </div>
          <div>
            <dt>{{ t('onboarding.pendingCount') }}</dt>
            <dd>{{ state.pendingVerifications }}</dd>
          </div>
          <div>
            <dt>{{ t('onboarding.verifiedCount') }}</dt>
            <dd>{{ state.verifiedUsers }}</dd>
          </div>
          <div v-for="(count, key) in state.claimStates" :key="key">
            <dt>{{ l('states.' + key) }}</dt>
            <dd>{{ count }}</dd>
          </div>
          <div v-for="(count, key) in state.claimReasons" :key="key">
            <dt>{{ l('reasons.' + key) }}</dt>
            <dd>{{ count }}</dd>
          </div>
        </dl>
      </details>
    </template>
    <CanaryActionDialog
      v-if="action"
      :action="action"
      :remaining-seconds="
        action.kind === 'admit' && canActivate ? remainingSeconds(action.owner) : 0
      "
      @close="action = null"
      @refresh="refresh"
      @completed="completed"
    />
  </section>
</template>

<style scoped>
.registration-diagnostics {
  max-width: 1100px;
  display: grid;
  gap: 1rem;
}
.canary-panel {
  display: grid;
  gap: 1rem;
  padding: clamp(0.75rem, 3vw, 1.25rem);
}
.canary-toolbar,
.canary-actions,
.canary-status {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}
.canary-toolbar {
  justify-content: space-between;
}
.canary-status {
  gap: 0.4rem;
}
.canary-status :deep(svg) {
  width: 1rem;
  height: 1rem;
}
.canary-summary,
.canary-owner-data {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 13rem), 1fr));
  gap: 0.65rem 1rem;
}
dl > div {
  padding: 0.55rem 0;
  border-bottom: 1px solid var(--border);
}
dt {
  color: var(--muted);
}
dd {
  margin: 0.25rem 0 0;
}
.canary-owners,
.canary-reasons {
  display: grid;
  gap: 0.75rem;
}
.canary-owners {
  list-style: none;
  padding: 0;
}
.canary-owners > li {
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 0.75rem;
  display: grid;
  gap: 0.75rem;
}
.canary-reasons {
  padding-inline-start: 1.25rem;
}
code,
.canary-toolbar,
dd {
  overflow-wrap: anywhere;
}
summary {
  cursor: pointer;
}
</style>
