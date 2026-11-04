<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '../api/client.js'
import { locale, t } from '../i18n/index.js'
import AppIcon from '../components/AppIcon.vue'
import CanaryActionDialog from '../components/CanaryActionDialog.vue'
import LoadingButton from '../components/LoadingButton.vue'

const approvalLabels = {
  missing: 'approvalMissing',
  approved: 'approvalApproved',
  expired: 'approvalExpired',
  stopped: 'approvalStopped',
  stale: 'approvalStale',
}
const l = (key, params) => t('canary.' + key, params)
const state = ref(null)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const action = ref(null)
const selection = ref([])
const monotonicTime = ref(performance.now())
const deadline = new AbortController()
const management = computed(() => state.value?.management)
const canary = computed(() => state.value?.canary)
let serverOffset = 0
let timer
const serverNow = computed(() => monotonicTime.value + serverOffset)
const permitActive = computed(
  () =>
    canary.value?.operatorApproval === 'approved' &&
    Date.parse(management.value.permitExpiresAt) > serverNow.value,
)
const approvalState = computed(() =>
  canary.value.operatorApproval === 'approved' && !permitActive.value
    ? 'expired'
    : canary.value.operatorApproval,
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
const checked = (owner) => selection.value.some((row) => row.claimId === owner.claimId)
const eligible = (owner) => owner.eligible && !owner.windowClosed && remainingSeconds(owner) > 0
const canApprove = computed(
  () =>
    canary.value?.configurationReady &&
    !!management.value?.revision &&
    selection.value.length > 0 &&
    selection.value.every(eligible),
)
const canStop = computed(
  () =>
    management.value?.revision && ['approved', 'stale', 'expired'].includes(approvalState.value),
)

async function load(page = management.value?.page ?? 1, preserveSelection = false) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  if (!preserveSelection) selection.value = []
  try {
    const { data } = await api.request('/api/platform/registration-canary/owners?page=' + page, {
      signal: deadline.signal,
    })
    monotonicTime.value = performance.now()
    serverOffset = Date.parse(data.management.serverTime) - monotonicTime.value
    if (management.value?.revision !== data.management.revision) selection.value = []
    else if (preserveSelection) {
      const visible = new Map(data.management.owners.map((owner) => [owner.claimId, owner]))
      selection.value = selection.value
        .map((row) => visible.get(row.claimId) || row)
        .filter(eligible)
    }
    state.value = data
  } catch (cause) {
    if (deadline.signal.aborted) return
    state.value = null
    selection.value = []
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

function toggle(owner, selected) {
  if (!selected) selection.value = selection.value.filter((row) => row.claimId !== owner.claimId)
  else if (eligible(owner) && selection.value.length < management.value.claimLimit)
    selection.value = [...selection.value, owner]
}

function open(kind, owners = []) {
  if (busy.value) return
  notice.value = ''
  action.value = { kind, owners, revision: management.value.revision, versions: canary.value }
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
  if (owner.windowClosed || remainingSeconds(owner) <= 0) return l('windowClosed')
  if (!owner.eligible) return l('ownerNotEligible')
  if (owner.allowlisted && permitActive.value)
    return l(canary.value.admissionEnabled ? 'readyToAdmit' : 'waitingForEnv')
  return l('readyToApprove')
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
            <dt>{{ t('onboarding.operatorApproval') }}</dt>
            <dd class="canary-status">
              <AppIcon :name="permitActive ? 'check' : 'info'" aria-hidden="true" />
              {{ t('onboarding.' + approvalLabels[approvalState]) }}
            </dd>
          </div>
          <div>
            <dt>{{ l('expires') }}</dt>
            <dd>{{ date(management.permitExpiresAt) }}</dd>
          </div>
          <div>
            <dt>{{ l('allowlistLimit') }}</dt>
            <dd>
              {{ l('limitValue', { count: management.claimLimit, hours: management.permitHours }) }}
            </dd>
          </div>
          <div>
            <dt>{{ t('onboarding.pendingVerifiedClaims') }}</dt>
            <dd>{{ canary.pendingVerifiedClaims }}</dd>
          </div>
          <div>
            <dt>{{ t('onboarding.approvedPendingClaims') }}</dt>
            <dd>{{ permitActive ? canary.approvedPendingClaims : 0 }}</dd>
          </div>
          <div>
            <dt>{{ l('admittedOwners') }}</dt>
            <dd>{{ management.admittedOwners }}</dd>
          </div>
        </dl>
        <ul v-if="canary.reasonCodes.length" class="canary-reasons">
          <li v-for="reason in canary.reasonCodes" :key="reason">{{ l('reasons.' + reason) }}</li>
        </ul>
        <p v-if="!canary.admissionEnabled">{{ l('envClosed') }}</p>
        <p v-if="canary.admissionEnabled && approvalState === 'stopped'">{{ l('restoreEnv') }}</p>
        <button
          v-if="canStop"
          class="button secondary"
          type="button"
          :disabled="busy"
          @click="open('stop')"
        >
          {{ l('stop') }}
        </button>
      </section>

      <section class="surface canary-panel" aria-labelledby="canary-owners-title">
        <header class="canary-toolbar">
          <div>
            <h2 id="canary-owners-title">{{ l('owners') }}</h2>
            <p>{{ l('selection', { count: selection.length, limit: management.claimLimit }) }}</p>
          </div>
          <div class="canary-actions">
            <button
              v-if="selection.length"
              class="button secondary"
              type="button"
              :disabled="busy"
              @click="selection = []"
            >
              {{ l('clearSelection') }}
            </button>
            <button
              class="button primary"
              type="button"
              :disabled="busy || !canApprove"
              @click="open('approve', selection)"
            >
              {{ l('approve') }}
            </button>
          </div>
        </header>
        <p v-if="!management.owners.length">{{ l('noOwners') }}</p>
        <ul v-else class="canary-owners">
          <li v-for="owner in management.owners" :key="owner.claimId">
            <header class="canary-toolbar">
              <div>
                <h3>{{ owner.ownerName || owner.email || owner.claimId }}</h3>
                <p v-if="owner.email && owner.ownerName">{{ owner.email }}</p>
              </div>
              <span class="canary-status"
                ><AppIcon
                  :name="owner.period || (owner.allowlisted && permitActive) ? 'check' : 'info'"
                  aria-hidden="true"
                />
                {{ ownerStatus(owner) }}</span
              >
            </header>
            <dl class="canary-owner-data">
              <div>
                <dt>{{ l('workspace') }}</dt>
                <dd>{{ owner.workspace.name || owner.workspace.id }}</dd>
              </div>
              <div>
                <dt>{{ l('claimId') }}</dt>
                <dd>
                  <code>{{ owner.claimId }}</code>
                </dd>
              </div>
              <div>
                <dt>{{ l('verified') }}</dt>
                <dd>
                  <time :datetime="owner.verifiedAt">{{ date(owner.verifiedAt) }}</time>
                </dd>
              </div>
              <div>
                <dt>{{ l('claimState') }}</dt>
                <dd>{{ l('states.' + owner.state) }}</dd>
              </div>
              <div v-if="owner.period">
                <dt>{{ l('periodAssigned') }}</dt>
                <dd>
                  <code>{{ owner.period.id }}</code
                  ><br />{{ date(owner.period.startsAt) }} → {{ date(owner.period.endsAt) }}
                </dd>
              </div>
            </dl>
            <div v-if="!owner.period" class="canary-actions">
              <label v-if="eligible(owner)" class="canary-status">
                <input
                  type="checkbox"
                  :checked="checked(owner)"
                  :disabled="
                    busy ||
                    !canary.configurationReady ||
                    (!checked(owner) && selection.length >= management.claimLimit)
                  "
                  @change="toggle(owner, $event.target.checked)"
                />
                {{ l('selectOwner', { owner: owner.ownerName || owner.email || owner.claimId }) }}
              </label>
              <button
                v-if="owner.allowlisted && permitActive"
                class="button primary"
                type="button"
                :disabled="busy || !eligible(owner) || !canary.admissionEnabled"
                @click="open('admit', [owner])"
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
            @click="load(management.page - 1, true)"
          >
            {{ l('previous') }}
          </button>
          <button
            class="button secondary"
            type="button"
            :disabled="busy || !management.hasMore"
            @click="load(management.page + 1, true)"
          >
            {{ l('next') }}
          </button>
        </nav>
      </section>

      <details>
        <summary>{{ l('otherDiagnostics') }}</summary>
        <dl class="canary-summary">
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
        action.kind === 'admit' && permitActive && canary.admissionEnabled
          ? remainingSeconds(action.owners[0])
          : 0
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
