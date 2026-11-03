<script setup>
import { ref, watch, computed, onBeforeUnmount } from 'vue'
import { api } from '../api/client.js'
import { t, i18n, locale, formatApiError } from '../i18n/index.js'
import LoadingButton from './LoadingButton.vue'
const props = defineProps({ workspaceId: String, platform: Boolean, targetId: String })
const l = (k) => t('preparation.' + k)
const reasonLabel = (code) => {
  for (const prefix of ['preparation.reasons.', 'inventory.reasons.'])
    if (i18n.global.te(prefix + code, 'en')) return t(prefix + code)
  return l('unknown')
}
const prefix = computed(() =>
  props.platform
    ? '/api/platform/workspaces/' + props.workspaceId + '/resource-selection'
    : '/api/admin/resource-selection',
)
const options = ref(null),
  selection = ref({}),
  frozen = ref(null),
  error = ref(''),
  notice = ref(''),
  busy = ref(false),
  reviews = ref([]),
  confirmation = ref(''),
  reason = ref('operator_verified'),
  confirmed = ref(false),
  serviceDays = ref(30),
  paidReason = ref('')
const approval = computed(() => frozen.value?.availableAction || null)
const rearmGroups = computed(() => options.value?.rearmGroups || [])
const featureNames = { agent: 'AI Agent API', api: 'External API', sso: 'SSO', webhook: 'Webhooks' }
let approvalIntent = null
let epoch = 0,
  intent = null,
  targetEpoch = 0,
  reviewIntents = new Map()
const groups = ['projects', 'developers', 'clients', 'agents', 'invitations']
const short = (id) => (id ? id.slice(0, 8) + '…' + id.slice(-4) : '—')
const clients = computed(() =>
  (options.value?.resources.clients || []).filter((c) =>
    c.projectIds.some((id) => selection.value.projects?.includes(id)),
  ),
)
function rows(group) {
  return group === 'clients' ? clients.value : options.value.resources[group]
}
async function load() {
  const current = ++epoch
  busy.value = true
  error.value = ''
  frozen.value = null
  reviews.value = []
  confirmation.value = ''
  reviewIntents.clear()
  notice.value = ''
  options.value = null
  intent = null
  approvalIntent = null
  confirmed.value = false
  try {
    const r = await api.request(prefix.value)
    if (epoch !== current) return
    options.value = r.data
    selection.value = Object.fromEntries(
      groups.map((g) => [g, r.data.resources[g].map((v) => v.id).filter(Boolean)]),
    )
  } catch (e) {
    if (epoch === current) error.value = formatApiError(e)
  } finally {
    if (epoch === current) busy.value = false
  }
}
function changed() {
  frozen.value = null
  intent = null
  confirmed.value = false
  selection.value.clients = selection.value.clients.filter((id) =>
    clients.value.some((c) => c.id === id),
  )
}
async function freeze() {
  confirmed.value = false
  const current = epoch
  const target = targetEpoch
  const url = prefix.value
  busy.value = true
  error.value = ''
  notice.value = ''
  const body = {
    targetVersionId: props.targetId,
    selection: JSON.parse(JSON.stringify(selection.value)),
  }
  const hash = JSON.stringify(body)
  if (!intent || intent.hash !== hash) intent = { hash, key: crypto.randomUUID() }
  try {
    const r = await api.request(url + '/previews', {
      method: 'POST',
      body,
      headers: {
        'Idempotency-Key': intent.key,
        'If-Match': '"revision:' + options.value.subscriptionRevision + '"',
      },
    })
    if (current !== epoch || target !== targetEpoch) return
    frozen.value = r.data
    intent = null
  } catch (e) {
    if (current === epoch && target === targetEpoch)
      error.value = e.status === 409 ? l('expired') : formatApiError(e)
  } finally {
    if (current === epoch) busy.value = false
  }
}
async function verify() {
  const current = epoch
  const target = targetEpoch
  busy.value = true
  error.value = ''
  try {
    await api.request(prefix.value + '/previews/' + frozen.value.id)
    if (current !== epoch || target !== targetEpoch) return
    notice.value = l(approval.value ? 'approvalVerified' : 'verified')
  } catch (e) {
    if (current !== epoch || target !== targetEpoch) return
    error.value = e.status === 409 ? l('expired') : formatApiError(e)
    frozen.value = null
  } finally {
    if (current === epoch) busy.value = false
  }
}
async function apply(action) {
  if (!confirmed.value || busy.value) return
  const current = epoch
  const preview = frozen.value
  const isRearm = action === 'rearm'
  const url = prefix.value + (isRearm ? '/rearm' : '/previews/' + preview.id + '/' + action)
  const revision = isRearm ? options.value.subscriptionRevision : preview.subscriptionRevision
  const body = isRearm
    ? { groups: rearmGroups.value }
    : action === 'approve-paid'
      ? { serviceDays: serviceDays.value, reason: paidReason.value }
      : {}
  const fingerprint = JSON.stringify([url, revision, body])
  if (approvalIntent?.fingerprint !== fingerprint)
    approvalIntent = { fingerprint, key: crypto.randomUUID() }
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    const result = await api.request(url, {
      method: 'POST',
      body,
      headers: {
        'Idempotency-Key': approvalIntent.key,
        'If-Match': '"revision:' + revision + '"',
      },
    })
    if (epoch !== current) return
    await load()
    if (epoch === current + 1)
      notice.value =
        result.data?.state === 'scheduled'
          ? t('preparation.approvalScheduled', { at: result.data.effectiveAt })
          : l('approvalSaved')
  } catch (cause) {
    if (epoch !== current) return
    error.value = cause.status === 409 ? l('expired') : formatApiError(cause)
    if (cause.status === 409) {
      frozen.value = null
      confirmed.value = false
    }
  } finally {
    if (epoch === current) busy.value = false
  }
}
async function reviewList() {
  const current = epoch
  try {
    const response = await api.request(prefix.value + '/accounting-review')
    if (current === epoch) reviews.value = response.data
  } catch (e) {
    if (current === epoch) error.value = formatApiError(e)
  }
}
async function review(row, action) {
  const current = epoch
  busy.value = true
  error.value = ''
  const body = {
    clientId: row.clientId,
    action,
    inputHash: row.inputHash,
    reason: reason.value,
    confirmation: confirmation.value,
  }
  const hash = JSON.stringify([body, row.revision])
  if (!reviewIntents.has(hash)) reviewIntents.set(hash, crypto.randomUUID())
  try {
    await api.request(prefix.value + '/accounting-review', {
      method: 'POST',
      body,
      headers: {
        'Idempotency-Key': reviewIntents.get(hash),
        'If-Match': '"revision:' + row.revision + '"',
      },
    })
    if (current !== epoch) return
    await load()
    if (current + 1 !== epoch) return
    notice.value = l('reviewSaved')
    await reviewList()
  } catch (e) {
    if (current === epoch) error.value = e.status === 409 ? l('inputChanged') : formatApiError(e)
  } finally {
    if (current === epoch) busy.value = false
  }
}
onBeforeUnmount(() => {
  epoch++
})
watch(() => [props.workspaceId, props.platform], load, { immediate: true })
watch(
  () => props.targetId,
  () => {
    targetEpoch++
    confirmed.value = false
    frozen.value = null
    intent = null
  },
)
</script>
<template>
  <section class="resource-selection" :aria-busy="busy">
    <section v-if="!props.platform && options" class="subscription-overview">
      <template v-if="options.subscription">
        <h2>
          {{
            options.subscription.translations[locale]?.name ||
            options.subscription.translations.en.name
          }}
        </h2>
        <p>{{ t('account.subscriptionPhase.' + options.subscription.phase) }}</p>
        <p v-if="options.subscription.endsAt">
          {{ l('expires') }} ·
          <time :datetime="options.subscription.endsAt">{{ options.subscription.endsAt }}</time>
        </p>
        <dl>
          <div v-for="(limit, key) in options.subscription.limits" :key="key">
            <dt>{{ t('inventory.resources.' + key) }}</dt>
            <dd>
              {{ options.usage[key]?.state === 'ready' ? options.usage[key].count : '—' }} /
              {{ limit.kind === 'unlimited' ? l('unlimited') : limit.value }}
            </dd>
          </div>
        </dl>
      </template>
      <p v-else>{{ l('reasons.subscription_missing_or_incomplete') }}</p>
    </section>
    <h2>{{ l('title') }}</h2>
    <p>{{ l('intro') }}</p>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <LoadingButton v-if="error" class="button secondary" :busy="busy" @click="load">{{
      t('inventory.refresh')
    }}</LoadingButton>
    <p v-if="notice" role="status">{{ notice }}</p>
    <div v-if="options" class="resource-groups">
      <fieldset v-for="group in groups" :key="group">
        <legend>{{ l(group) }} · {{ selection[group]?.length || 0 }}</legend>
        <p v-if="!rows(group).length">{{ l('empty') }}</p>
        <label v-for="row in rows(group)" :key="row.id || 'unknown'" class="resource-option"
          ><input
            v-model="selection[group]"
            type="checkbox"
            :value="row.id"
            :disabled="busy || !row.id || row.owner"
            @change="changed"
          /><span
            >{{ row.label && row.label !== row.id ? row.label : short(row.id)
            }}<small
              v-if="
                row.label && row.label !== row.id && ['clients', 'agents'].includes(group) && row.id
              "
            >
              {{ row.id }} </small
            ><small v-if="row.owner">{{ l('owner') }}</small
            ><small v-if="row.expiresAt">{{ row.expiresAt }} UTC</small></span
          ></label
        >
      </fieldset>
    </div>
    <LoadingButton
      class="button primary"
      :busy="busy"
      :disabled="!options || !props.targetId"
      @click="freeze"
      >{{ l('freeze') }}</LoadingButton
    >
    <div v-if="frozen" class="frozen-summary">
      <h3>{{ frozen.state === 'ready' ? l('ready') : l('unknown') }}</h3>
      <ul v-if="frozen.reasonCodes.length">
        <li v-for="code in frozen.reasonCodes" :key="code">{{ reasonLabel(code) }}</li>
      </ul>
      <p>{{ l('expires') }}: {{ frozen.expiresAt }}</p>
      <dl>
        <div v-for="(count, key) in frozen.counts" :key="key">
          <dt>{{ t('inventory.resources.' + key) }}</dt>
          <dd>
            {{ count }} /
            {{
              frozen.limits[key].kind === 'unlimited' ? l('unlimited') : frozen.limits[key].value
            }}
            <strong v-if="frozen.excess[key]">
              · +{{ frozen.excess[key] }} {{ l('excess') }}</strong
            >
          </dd>
        </div>
      </dl>
      <p v-for="(ids, group) in frozen.wouldHold" :key="group">
        {{ l(group) }} · {{ l('held') }}: {{ ids.length }}
      </p>
      <ul>
        <li v-for="(state, key) in frozen.premium" :key="key">
          {{ t('inventory.resources.' + key) }}:
          {{
            typeof state === 'object'
              ? l('unknown')
              : state === 'would_hold_execution'
                ? l('held')
                : l('kept')
          }}
        </li>
      </ul>
      <p>{{ l('remaining') }}</p>
      <LoadingButton class="button secondary" :busy="busy" @click="verify">{{
        l('verify')
      }}</LoadingButton>
    </div>
    <form
      v-if="approval || rearmGroups.length"
      class="surface admin-form"
      @submit.prevent="apply(approval || 'rearm')"
    >
      <h3>{{ l('actions.' + (approval || 'rearm')) }}</h3>
      <p v-if="!approval">{{ rearmGroups.map((group) => featureNames[group]).join(' · ') }}</p>
      <template v-if="approval === 'approve-paid'">
        <label
          >{{ l('serviceDays')
          }}<input v-model.number="serviceDays" type="number" min="1" max="36600" required
        /></label>
        <label
          >{{ l('reason')
          }}<input v-model="paidReason" pattern="[a-z][a-z0-9_]{0,59}" maxlength="60" required
        /></label>
      </template>
      <label class="resource-option"
        ><input v-model="confirmed" type="checkbox" />{{ l('approveConfirmation') }}</label
      >
      <LoadingButton class="button primary" type="submit" :busy="busy" :disabled="!confirmed">
        {{ l('actions.' + (approval || 'rearm')) }}
      </LoadingButton>
    </form>
    <details @toggle="$event.target.open && reviewList()">
      <summary>{{ l('review') }}</summary>
      <p>{{ l('reviewNotice') }}</p>
      <p v-if="!reviews.length">{{ l('empty') }}</p>
      <label>{{ l('confirmation') }}<input v-model="confirmation" autocomplete="off" /></label
      ><label>{{ l('reason') }}<input v-model="reason" maxlength="60" /></label>
      <article v-for="row in reviews" :key="row.clientId" class="accounting-review-row">
        <code>{{ short(row.clientId) }} → {{ short(row.identityId) }}</code
        ><span>{{ t('inventory.states.' + row.state) }}</span>
        <p>
          {{
            row.provenance.contradiction
              ? l('unknown')
              : row.provenance.verifiedSources
                ? l('provenSource')
                : row.provenance.contactHint
                  ? l('contactHint')
                  : l('fallbackHint')
          }}
        </p>
        <button
          class="button secondary"
          :disabled="busy || confirmation !== 'ACCOUNTING ONLY'"
          @click="review(row, 'confirm')"
        >
          {{ l('confirm') }}</button
        ><button
          class="button secondary"
          :disabled="
            busy || confirmation !== 'ACCOUNTING ONLY' || row.provenance.verifiedSources > 0
          "
          @click="review(row, 'separate')"
        >
          {{ l('separate') }}
        </button>
      </article>
    </details>
  </section>
</template>
<style scoped>
.resource-selection {
  margin-block: 24px;
  display: grid;
  gap: 16px;
}
.resource-groups {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 230px), 1fr));
  gap: 12px;
}
fieldset {
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px;
  min-width: 0;
}
.resource-option {
  display: flex;
  align-items: start;
  gap: 10px;
  margin-block: 10px;
  overflow-wrap: anywhere;
}
.resource-option input {
  width: 18px;
  height: 18px;
  flex: none;
  margin: 2px 0;
}
.resource-option small {
  display: block;
  color: var(--text-subtle);
  margin-top: 4px;
}
.frozen-summary,
details {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 16px;
}
dl > div {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
  padding-block: 10px;
  border-bottom: 1px solid var(--border);
}
dd {
  margin: 0;
}
.accounting-review-row {
  padding-block: 14px;
  border-bottom: 1px solid var(--border);
  overflow-wrap: anywhere;
}
.accounting-review-row .button {
  margin: 6px;
}
details > label {
  display: grid;
  gap: 6px;
  margin-block: 12px;
}
details input {
  max-width: 100%;
  padding: 10px;
  background: var(--surface);
  color: var(--text);
  border: 1px solid var(--border);
  border-radius: 8px;
}
summary {
  cursor: pointer;
}
</style>
