<script setup>
import { computed, ref, nextTick, watch } from 'vue'
import { api } from '../api/client.js'
import { t, i18n } from '../i18n/index.js'
import { useRequestFeedback } from '../services/requestFeedback.js'
import {
  eligibleResource,
  exceedsLimit,
  formatLimit,
  planName,
  quotaGroups,
  selectedCounts,
  selectionGroups,
  suggestedSelection,
} from '../services/subscription.js'
import BaseDialog from './BaseDialog.vue'
import LoadingButton from './LoadingButton.vue'
import SubscriptionLoadError from './SubscriptionLoadError.vue'
import SubscriptionPlanCard from './SubscriptionPlanCard.vue'

const props = defineProps({ workspaceId: { type: String, required: true } })
const emit = defineEmits(['close', 'assigned'])
const base = '/api/platform/workspaces/' + props.workspaceId + '/resource-selection'
const { busy, error, retrySeconds, run } = useRequestFeedback()
const step = ref(1)
const model = ref(null)
const target = ref(null)
const selection = ref({})
const preview = ref(null)
const endDate = ref('')
const replacement = ref(false)
const search = ref('')
const reviewMessage = ref('')
const stepHeading = ref(null)
watch(step, async () => {
  await nextTick()
  stepHeading.value?.focus()
})
const today = () => new Date().toISOString().slice(0, 10)
const planCodes = { basic: 'Basic', freelancer: 'Freelancer', studio: 'Studio' }
const label = (key) => t('subscriptionUx.' + key)
const groupName = (key) => t('preparation.' + key)
const resources = computed(() => model.value?.resources)
const usage = computed(() => {
  if (!target.value) return {}
  return selectedCounts(
    resources.value,
    Object.fromEntries(
      selectionGroups.map((key) => [
        key,
        resources.value[key].map((row) => row.id).filter(Boolean),
      ]),
    ),
  )
})
const overages = computed(() =>
  Object.entries(usage.value).filter(([key, count]) =>
    exceedsLimit(count, target.value.limits[key]),
  ),
)
const counts = computed(() =>
  target.value ? selectedCounts(resources.value, selection.value) : {},
)
const validSelection = computed(
  () =>
    target.value &&
    resources.value.developers.filter((row) => row.owner).length === 1 &&
    Object.entries(counts.value).every(
      ([key, count]) => !exceedsLimit(count, target.value.limits[key]),
    ),
)
const held = computed(() =>
  selectionGroups.some((group) => preview.value?.wouldHold[group]?.length),
)
const unavailableFeatures = computed(() =>
  Object.keys(target.value?.limits || {}).filter(
    (key) =>
      ['api.external.enabled', 'api.agents.enabled', 'webhooks.enabled', 'sso.enabled'].includes(
        key,
      ) && !target.value.limits[key].value,
  ),
)
let previewKey, applyIntent

async function load() {
  const result = await run((signal) => api.request(base + '/manual', { signal }))
  if (result) {
    model.value = result.data
    step.value = 1
    target.value = preview.value = null
    reviewMessage.value = ''
  }
}
function choose(plan) {
  if (!plan) return
  target.value = plan
  selection.value = suggestedSelection(resources.value, plan.limits)
  search.value = ''
  preview.value = null
  previewKey = null
  applyIntent = null
  reviewMessage.value = ''
  replacement.value = false
  step.value = 2
}
function rows(group) {
  const query = search.value.trim().toLocaleLowerCase()
  return resources.value[group].filter(
    (row) =>
      !query || [row.label, row.email].some((value) => value?.toLocaleLowerCase().includes(query)),
  )
}
function allowed(group, row) {
  if (row.owner || busy.value || !eligibleResource(group, row, selection.value.projects))
    return false
  if (selection.value[group].includes(row.id)) return true
  const next = { ...selection.value, [group]: [...selection.value[group], row.id] }
  const key = group === 'invitations' ? quotaGroups.developers : quotaGroups[group]
  return !exceedsLimit(selectedCounts(resources.value, next)[key], target.value.limits[key])
}
function toggle(group, row) {
  if (!allowed(group, row)) return
  const current = selection.value[group]
  selection.value[group] = current.includes(row.id)
    ? current.filter((id) => id !== row.id)
    : [...current, row.id]
  if (group === 'projects') {
    for (const related of ['clients', 'agents', 'invitations'])
      selection.value[related] = selection.value[related].filter((id) =>
        eligibleResource(
          related,
          resources.value[related].find((candidate) => candidate.id === id),
          selection.value.projects,
        ),
      )
  }
  previewKey = null
  preview.value = null
}
function requestHeaders(key, revision) {
  return { 'Idempotency-Key': key, 'If-Match': '"revision:' + revision + '"' }
}
async function review() {
  if (!validSelection.value) return
  reviewMessage.value = ''
  previewKey ||= crypto.randomUUID()
  const result = await run((signal) =>
    api.request(base + '/manual/previews', {
      method: 'POST',
      body: { targetVersionId: target.value.id, selection: selection.value },
      headers: requestHeaders(previewKey, model.value.subscriptionRevision),
      signal,
    }),
  )
  if (!result) return
  if (result.data.availableAction !== 'assign-manual') {
    const reasons = result.data.reasonCodes.filter(
      (key) => key !== 'participation_cycle_adapter_required',
    )
    reviewMessage.value =
      reasons
        .map((key) => {
          const path = ['preparation.reasons.', 'inventory.reasons.'].find((prefix) =>
            i18n.global.te(prefix + key, 'en'),
          )
          return path ? t(path + key) : label('selectionNeedsReview')
        })
        .join(' ') || label('selectionNeedsReview')
    return
  }
  preview.value = result.data
  step.value = 3
}
async function assign() {
  if (!preview.value || (model.value.replacementRequired && !replacement.value)) return
  if (target.value.accessClass === 'paid' && (!endDate.value || endDate.value < today())) return
  const body = {
    endDate: target.value.accessClass === 'paid' ? endDate.value : null,
    replaceActivePeriod: replacement.value,
  }
  const fingerprint = JSON.stringify([preview.value.id, body])
  if (applyIntent?.fingerprint !== fingerprint)
    applyIntent = { fingerprint, key: crypto.randomUUID() }
  const result = await run((signal) =>
    api.request(base + '/previews/' + preview.value.id + '/assign-manual', {
      method: 'POST',
      body,
      headers: requestHeaders(applyIntent.key, preview.value.subscriptionRevision),
      signal,
    }),
  )
  if (result?.data.state === 'applied') emit('assigned')
}
load()
</script>

<template>
  <BaseDialog
    open
    id="subscription-change-title"
    :title="label('changePlan')"
    @close="!busy && emit('close')"
  >
    <ol class="wizard-steps">
      <li
        v-for="(key, index) in ['choosePlan', 'impact', 'confirmation']"
        :key="key"
        :aria-current="step === index + 1 ? 'step' : undefined"
      >
        {{ label(key) }}
      </li>
    </ol>
    <SubscriptionLoadError
      :error="error"
      :seconds="retrySeconds"
      :retry="
        error?.status === 409 || !model ? load : step === 2 ? review : step === 3 ? assign : null
      "
    />
    <p v-if="busy" role="status">{{ t('nav.loading') }}</p>
    <template v-if="model && step === 1">
      <p v-if="!model.assignableVersions.length">{{ t('manualSubscription.unavailable') }}</p>
      <div v-else class="wizard-plans">
        <template v-for="(name, code) in planCodes" :key="code">
          <SubscriptionPlanCard
            :plan="model.assignableVersions.find((plan) => plan.code === code)"
            :code="name"
          >
            <button
              type="button"
              class="button secondary"
              :disabled="
                busy ||
                retrySeconds > 0 ||
                !model.assignableVersions.some((plan) => plan.code === code)
              "
              @click="choose(model.assignableVersions.find((plan) => plan.code === code))"
            >
              {{ t('subscriptionUx.chooseNamed', { plan: name }) }}
            </button>
          </SubscriptionPlanCard>
        </template>
      </div>
    </template>
    <section v-if="step === 2" class="wizard-body">
      <h3 ref="stepHeading" tabindex="-1">{{ planName(target) }}</h3>
      <ul v-if="overages.length">
        <li v-for="[key, count] in overages" :key="key">
          {{ t('inventory.resources.' + key) }} · {{ count }} /
          {{ formatLimit(target.limits[key]) }}
        </li>
      </ul>
      <p v-else>{{ label('fits') }}</p>
      <p v-if="unavailableFeatures.length">
        {{ label('featuresPaused') }} ·
        {{ unavailableFeatures.map((key) => t('inventory.resources.' + key)).join(', ') }}
      </p>
      <template v-if="overages.length">
        <p>{{ label('holds') }}</p>
        <label class="wizard-search"
          >{{ label('findResource') }} <input v-model="search" type="search"
        /></label>
        <div class="wizard-resources">
          <fieldset v-for="group in selectionGroups" :key="group">
            <legend>
              {{ groupName(group) }} ·
              {{
                group === 'developers' ? counts[quotaGroups.developers] : selection[group].length
              }}
              <template v-if="quotaGroups[group]">
                / {{ formatLimit(target.limits[quotaGroups[group]]) }}</template
              >
            </legend>
            <div class="wizard-resource-list">
              <p v-if="!rows(group).length" class="muted">{{ t('preparation.empty') }}</p>
              <label v-for="(row, index) in rows(group)" :key="row.id || index">
                <input
                  type="checkbox"
                  :checked="selection[group].includes(row.id)"
                  :disabled="!allowed(group, row)"
                  @change="toggle(group, row)"
                />
                <span>
                  {{ row.label || label('unnamed') }}
                  <small v-if="row.owner">{{ t('preparation.owner') }}</small>
                  <small v-if="row.email">{{ row.email }}</small>
                  <small v-if="group === 'invitations'">{{
                    label(row.eligible ? 'pendingInvite' : 'unavailableInvite')
                  }}</small>
                </span>
              </label>
            </div>
          </fieldset>
        </div>
      </template>
      <p v-if="reviewMessage" role="alert">{{ reviewMessage }}</p>
      <div class="wizard-actions">
        <button type="button" class="button secondary" :disabled="busy" @click="step = 1">
          {{ label('back') }}
        </button>
        <LoadingButton
          class="button primary"
          :busy="busy"
          :disabled="!validSelection || retrySeconds > 0"
          @click="review"
          >{{ label('continue') }}</LoadingButton
        >
      </div>
    </section>
    <form v-if="step === 3" class="admin-form wizard-body" @submit.prevent="assign">
      <h3 ref="stepHeading" tabindex="-1">
        {{ planName(model.subscription) }} → {{ planName(target) }}
      </h3>
      <label v-if="target.accessClass === 'paid'">
        {{ t('manualSubscription.endDate') }}
        <input v-model="endDate" type="date" :min="today()" :disabled="busy" required />
      </label>
      <p v-else>{{ t('manualSubscription.noEnd') }}</p>
      <ul class="wizard-counts">
        <li v-for="group in selectionGroups" :key="group">
          {{ groupName(group) }} · {{ preview.selection[group].length }}
        </li>
      </ul>
      <details>
        <summary>{{ label('retained') }}</summary>
        <div class="wizard-resource-list">
          <p
            v-for="group in selectionGroups.filter((key) => preview.selection[key].length)"
            :key="group"
          >
            {{ groupName(group) }} ·
            {{
              resources[group]
                .filter((row) => preview.selection[group].includes(row.id))
                .map((row) => row.label || label('unnamed'))
                .join(', ')
            }}
          </p>
        </div>
      </details>
      <details v-if="held">
        <summary>{{ label('paused') }}</summary>
        <div class="wizard-resource-list">
          <p
            v-for="group in selectionGroups.filter((key) => preview.wouldHold[key].length)"
            :key="group"
          >
            {{ groupName(group) }} ·
            {{
              resources[group]
                .filter((row) => preview.wouldHold[group].includes(row.id))
                .map((row) => row.label || label('unnamed'))
                .join(', ')
            }}
          </p>
        </div>
      </details>
      <p v-if="held">{{ label('holds') }}</p>
      <p v-if="target.accessClass === 'paid'">{{ label('rearmHint') }}</p>
      <p>{{ label('previewOnly') }}</p>
      <label v-if="model.replacementRequired" class="wizard-check">
        <input v-model="replacement" type="checkbox" :disabled="busy" required />
        {{ t('manualSubscription.replace') }}
      </label>
      <div class="wizard-actions">
        <button type="button" class="button secondary" :disabled="busy" @click="step = 2">
          {{ label('back') }}
        </button>
        <LoadingButton
          type="submit"
          class="button primary"
          :busy="busy"
          :disabled="retrySeconds > 0 || (model.replacementRequired && !replacement)"
          >{{ label('assign') }}</LoadingButton
        >
      </div>
    </form>
  </BaseDialog>
</template>

<style scoped>
.wizard-steps,
.wizard-counts {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 28px;
  padding-inline-start: 22px;
  margin-block: 0 18px;
}
.wizard-steps li {
  color: var(--muted);
}
.wizard-steps [aria-current] {
  color: var(--text);
  font-weight: 700;
}
.wizard-plans,
.wizard-resources {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 210px), 1fr));
  gap: 12px;
}
.wizard-body {
  display: grid;
  gap: 14px;
}
.wizard-body :is(h3, p, ul) {
  margin-block: 0;
}
.wizard-search {
  display: grid;
  gap: 6px;
}
.wizard-resource-list {
  max-height: 190px;
  overflow-y: auto;
}
.wizard-resource-list label,
.wizard-check {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding-block: 6px;
  overflow-wrap: anywhere;
}
.wizard-resource-list small {
  display: block;
  color: var(--muted);
}
.wizard-resources fieldset {
  min-width: 0;
  border: 1px solid var(--border);
  border-radius: 8px;
}
.wizard-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 8px;
}
:global(dialog[aria-labelledby='subscription-change-title']) {
  width: min(920px, calc(100vw - 24px));
  max-width: none;
}
</style>
