<script setup>
import { ref, watch, computed } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api/client.js'
import { t, locale, formatApiError } from '../i18n/index.js'
import LoadingButton from '../components/LoadingButton.vue'
const route = useRoute()
const l = (key) => t('inventory.' + key)
const state = (value) => l('states.' + value)
const resource = (key) => l('resources.' + key)
const reason = (key) => l('reasons.' + key)
const busy = ref(false),
  error = ref(''),
  data = ref(null),
  versions = ref([]),
  versionPage = ref(0),
  moreVersions = ref(false),
  target = ref(''),
  cursors = ref([0])
const unavailableMetrics = computed(() =>
  !data.value
    ? []
    : data.value.target
      ? data.value.decisions
          .filter((d) => d.measurementState === 'unavailable')
          .map((d) => ({ key: d.key, reasons: d.reasonCodes }))
      : Object.entries(data.value.usage)
          .filter(([, m]) => m.state === 'unavailable' && m.reason !== 'not_a_capacity_metric')
          .map(([key, m]) => ({ key, reasons: m.reason_codes })),
)
let revision = 0
const limitText = (value) =>
  value === null || value === undefined
    ? '—'
    : value.kind === 'unlimited'
      ? '∞'
      : value.kind === 'enum'
        ? t('planManagement.enumNames.' + value.value)
        : typeof value.value === 'boolean'
          ? t('planManagement.' + (value.value ? 'yes' : 'no'))
          : String(value.value ?? '—')
async function loadVersions() {
  try {
    const result = await api.request(
      '/api/platform/published-plan-versions?page=' + (versionPage.value + 1),
    )
    versions.value.push(...result.data.filter((v) => v.accessClass !== 'compatibility'))
    versionPage.value = result.meta.page
    moreVersions.value = result.meta.page < result.meta.lastPage
  } catch (e) {
    error.value = formatApiError(e)
  }
}
async function load() {
  const current = ++revision
  busy.value = true
  error.value = ''
  data.value = null
  const params = new URLSearchParams()
  if (target.value) params.set('versionId', target.value)
  const workspaceId = route.params.workspaceId
  if (!workspaceId) {
    params.set('after', cursors.value.at(-1))
    params.set('limit', '25')
  }
  try {
    const result = await api.request(
      '/api/platform/workspace-inventory' + (workspaceId ? '/' + workspaceId : '') + '?' + params,
    )
    if (current === revision) data.value = workspaceId ? result.data : result
  } catch (e) {
    if (current === revision) error.value = formatApiError(e)
  } finally {
    if (current === revision) busy.value = false
  }
}
function next() {
  cursors.value.push(data.value.meta.nextAfter)
  load()
}
function previous() {
  cursors.value.pop()
  load()
}
watch(
  () => route.params.workspaceId,
  () => {
    target.value = ''
    cursors.value = [0]
    load()
  },
  { immediate: true },
)
loadVersions()
</script>
<template>
  <section class="platform-page inventory-page" :aria-busy="busy">
    <RouterLink v-if="route.params.workspaceId" to="/platform/plans/inventory"
      >← {{ l('back') }}</RouterLink
    >
    <h1>{{ route.params.workspaceId ? l('impact') : l('title') }}</h1>
    <p class="inventory-notice">{{ l('intro') }}</p>
    <div class="inventory-controls">
      <label
        >{{ l('target')
        }}<select v-model="target" @change="load">
          <option value="">{{ l('choose') }}</option>
          <option v-for="v in versions" :key="v.id" :value="v.id">
            {{ v.translations[locale]?.name || v.translations.en.name }} · v{{ v.number }}
          </option>
        </select></label
      >
      <button v-if="moreVersions" class="text-button" @click="loadVersions">
        {{ l('loadMore') }}
      </button>
      <LoadingButton class="button primary" :busy="busy" @click="load">{{
        l('refresh')
      }}</LoadingButton>
    </div>
    <p v-if="error" role="alert" class="error">{{ error }}</p>
    <template v-if="data && !route.params.workspaceId">
      <div class="inventory-summary">
        <div
          v-for="[key, value] in [
            ['total', data.coverage.total],
            ['uninitialized', data.coverage.uninitialized],
            ['compatibility', data.coverage.compatibilityObserve],
          ]"
          :key="key"
        >
          <span>{{ l(key) }}</span
          ><strong>{{ value }}</strong>
        </div>
      </div>
      <h2>{{ l('coverage') }}</h2>
      <p>
        <span
          v-for="(count, key) in data.coverage.checkpointStates"
          :key="key"
          class="inventory-chip"
          >{{ state(key) }}: {{ count }}</span
        >
      </p>
      <h2>{{ l('livePage') }}</h2>
      <p>
        <span
          v-for="[key, value] in [
            ['ready', data.pageSummary.ready],
            ['needs_review', data.pageSummary.needsReview],
            ['incomplete', data.pageSummary.incompleteAccounting],
          ]"
          :key="key"
          class="inventory-chip"
          >{{ state(key) }}: {{ value }}</span
        >
      </p>
      <ul v-if="Object.keys(data.pageSummary.potentialOverages).length">
        <li v-for="(count, key) in data.pageSummary.potentialOverages" :key="key">
          {{ resource(key) }}: {{ count }} · {{ state('would_deny') }}
        </li>
      </ul>
      <p v-if="!data.data.length">{{ l('empty') }}</p>
      <div class="inventory-workspaces">
        <article v-for="row in data.data" :key="row.workspaceId" class="inventory-card">
          <h3>
            {{ l('workspace') }} <code>{{ row.workspaceId }}</code>
          </h3>
          <p>
            {{ l('accounting') }}:
            <strong :data-state="row.accountingState">{{ state(row.accountingState) }}</strong>
          </p>
          <p>
            {{ l('subscription') }}:
            {{
              row.subscription
                ? state(row.subscription.kind) + ' · ' + row.subscription.configuredMode
                : state('uninitialized')
            }}
          </p>
          <ul v-if="row.reasonCodes.length">
            <li v-for="key in row.reasonCodes" :key="key">{{ reason(key) }}</li>
          </ul>
          <RouterLink :to="'/platform/plans/inventory/' + row.workspaceId"
            >{{ l('open') }} →</RouterLink
          >
        </article>
      </div>
      <nav class="inventory-pager" :aria-label="l('title')">
        <button class="button secondary" :disabled="cursors.length === 1 || busy" @click="previous">
          {{ l('previous') }}</button
        ><button class="button secondary" :disabled="!data.meta.hasMore || busy" @click="next">
          {{ l('next') }}
        </button>
      </nav>
    </template>
    <template v-else-if="data">
      <p>
        <code>{{ data.workspaceId }}</code>
      </p>
      <p>
        {{ l('timestamp') }}: <time :datetime="data.asOf">{{ data.asOf }}</time>
      </p>
      <p>
        {{ l('accounting') }}:
        <strong :data-state="data.accountingState">{{ state(data.accountingState) }}</strong>
      </p>
      <p>{{ l('configured') }}: {{ data.policy.configuredGlobalMode }}. {{ l('effective') }}</p>
      <p v-for="key in data.policy.reasonCodes" :key="key" class="inventory-notice">
        {{ reason(key) }}
      </p>
      <ul v-if="data.reasonCodes.length">
        <li v-for="key in data.reasonCodes" :key="key">{{ reason(key) }}</li>
      </ul>
      <p>{{ l('seats') }}</p>
      <p>{{ l('feature') }}</p>
      <p>{{ l('preserve') }}</p>
      <p>{{ l('drift') }}</p>
      <div v-if="data.target" class="inventory-target">
        <strong>{{ l('target') }} · v{{ data.target.number }}</strong
        ><code>{{ data.target.versionId }}</code
        ><code>{{ data.target.contentHash }}</code>
      </div>
      <div class="inventory-table-wrap">
        <table class="inventory-table">
          <caption>
            {{
              l('impact')
            }}
          </caption>
          <thead>
            <tr>
              <th>{{ l('resource') }}</th>
              <th>{{ l('actual') }}</th>
              <th>{{ l('limit') }}</th>
              <th>{{ l('decision') }}</th>
              <th>{{ l('excess') }}</th>
            </tr>
          </thead>
          <tbody v-if="data.target">
            <tr
              v-for="d in data.decisions.filter((d) => d.measurementState !== 'unavailable')"
              :key="d.key"
            >
              <th scope="row">{{ resource(d.key) }}</th>
              <td :data-label="l('actual')">
                {{ d.actual ?? '—' }}<small>{{ state(d.measurementState) }}</small>
              </td>
              <td :data-label="l('limit')">{{ limitText(d.limit) }}</td>
              <td :data-label="l('decision')" :data-state="d.state">
                {{ state(d.state)
                }}<small v-for="key in d.reasonCodes" :key="key">{{ reason(key) }}</small>
              </td>
              <td :data-label="l('excess')">{{ d.excess ?? '—' }}</td>
            </tr>
          </tbody>
          <tbody v-else>
            <tr
              v-for="(m, key) in data.usage"
              :key="key"
              v-show="m.reason !== 'not_a_capacity_metric' && m.state !== 'unavailable'"
            >
              <th scope="row">{{ resource(key) }}</th>
              <td :data-label="l('actual')">
                {{ m.count ?? '—' }}<small>{{ state(m.state) }}</small>
              </td>
              <td>—</td>
              <td>
                <small v-for="code in m.reason_codes" :key="code">{{ reason(code) }}</small>
              </td>
              <td>—</td>
            </tr>
          </tbody>
        </table>
      </div>
      <details v-if="unavailableMetrics.length" class="inventory-unavailable">
        <summary>{{ l('unavailableMetrics') }} · {{ unavailableMetrics.length }}</summary>
        <ul>
          <li v-for="metric in unavailableMetrics" :key="metric.key">
            <strong>{{ resource(metric.key) }}</strong> · {{ state('unavailable')
            }}<small v-for="key in metric.reasons" :key="key">{{ reason(key) }}</small>
          </li>
        </ul>
      </details>
    </template>
  </section>
</template>
<style scoped>
.inventory-page {
  max-width: 1280px;
}
.inventory-page p {
  margin-block: 12px;
  line-height: 1.5;
}
.inventory-page h2 {
  margin-block: 24px 12px;
}
.inventory-page h1 {
  margin-block: 16px;
}
.inventory-notice {
  padding: 16px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--action-soft, var(--surface-alt));
}
.inventory-controls {
  display: flex;
  align-items: end;
  gap: 16px;
  flex-wrap: wrap;
  margin-block: 20px;
}
.inventory-controls label {
  display: grid;
  gap: 8px;
  max-width: 100%;
}
.inventory-controls select {
  max-width: 100%;
  min-height: 42px;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
}
.inventory-summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
}
.inventory-summary > div,
.inventory-card {
  padding: 18px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: var(--surface);
  min-width: 0;
}
.inventory-summary strong {
  display: block;
  font-size: 28px;
}
.inventory-chip {
  display: inline-block;
  padding: 6px 12px;
  margin: 4px;
  border: 1px solid var(--border);
  border-radius: 8px;
}
.inventory-workspaces {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
  gap: 16px;
}
.inventory-card h3 {
  font-size: 15px;
  margin-top: 0;
}
.inventory-page code,
.inventory-page time {
  overflow-wrap: anywhere;
}
.inventory-page code {
  font-size: 12px;
}
.inventory-card code,
.inventory-target code {
  display: block;
  margin-block: 8px;
}
.inventory-target {
  margin-block: 20px;
}
.inventory-table-wrap {
  overflow-x: auto;
  max-width: 100%;
  border: 1px solid var(--border);
  border-radius: 10px;
}
.inventory-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 680px;
  text-align: start;
}
.inventory-table caption {
  text-align: start;
  padding: 16px;
  font-weight: 600;
}
.inventory-table th,
.inventory-table td {
  text-align: start;
  padding: 14px;
  border-bottom: 1px solid var(--border);
  vertical-align: top;
}
.inventory-table small {
  display: block;
  max-width: 270px;
  margin-top: 6px;
  color: var(--text-subtle);
}
.inventory-pager {
  display: flex;
  gap: 16px;
  margin-block: 20px;
  flex-wrap: wrap;
}
[data-state='would_deny'],
[data-state='needs_review'] {
  color: var(--danger, var(--text));
  font-weight: 650;
}
.inventory-table th {
  min-width: 140px;
}
.inventory-table td {
  min-width: 110px;
}
.inventory-table td:nth-child(4) {
  min-width: 200px;
}
.inventory-unavailable {
  margin-block: 20px;
  padding: 16px;
  border: 1px solid var(--border);
  border-radius: 10px;
}
.inventory-unavailable summary {
  cursor: pointer;
  font-weight: 600;
}
.inventory-unavailable li {
  margin-block: 12px;
}
.inventory-unavailable small {
  display: block;
  color: var(--text-subtle);
  margin-top: 4px;
}
@media (max-width: 600px) {
  .inventory-table,
  .inventory-table tbody {
    display: block;
    min-width: 0;
  }
  .inventory-table thead {
    display: none;
  }
  .inventory-table tr {
    display: grid;
    grid-template-columns: 1fr 1fr;
    padding: 14px;
    gap: 14px;
    border-top: 1px solid var(--border);
  }
  .inventory-table th,
  .inventory-table td,
  .inventory-table td:nth-child(4) {
    padding: 0;
    border: 0;
    min-width: 0;
  }
  .inventory-table th {
    grid-column: 1 / -1;
  }
  .inventory-table td::before {
    display: block;
    content: attr(data-label);
    font-size: 12px;
    color: var(--text-subtle);
    margin-bottom: 6px;
  }
  .inventory-table td:nth-child(4) {
    grid-column: 1 / -1;
  }
  .inventory-table td:last-child {
    grid-column: 1 / -1;
  }
  .inventory-table small {
    max-width: none;
  }
}
</style>
