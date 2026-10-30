<script setup>
import { ref, onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t, formatApiError, intlLocale } from '../i18n/index.js'
import LoadingButton from '../components/LoadingButton.vue'
const router = useRouter()
const usageWorkspace = ref('')
const tab = ref('overview'),
  period = ref('24h'),
  search = ref(''),
  page = ref(1)
const data = ref(null),
  detail = ref(null),
  busy = ref(false),
  error = ref(null)
let revision = 0,
  controller
const tabs = ['overview', 'workspaces', 'usage', 'audit']
const number = (value) =>
  new Intl.NumberFormat(intlLocale(), { maximumFractionDigits: 2 }).format(Number(value || 0))
const date = (value) =>
  value
    ? new Intl.DateTimeFormat(intlLocale(), {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
      }).format(new Date(value.replace(' ', 'T').replace(/Z?$/, 'Z')))
    : '—'
async function load() {
  const current = ++revision
  controller?.abort()
  controller = new AbortController()
  busy.value = true
  error.value = null
  data.value = null
  detail.value = null
  try {
    const query = new URLSearchParams({ period: period.value, page: String(page.value) })
    if (tab.value === 'workspaces') query.set('q', search.value)
    if (tab.value === 'usage' && usageWorkspace.value) query.set('workspace', usageWorkspace.value)
    const result = await api.request(`/api/platform/${tab.value}?${query}`, {
      signal: controller.signal,
    })
    if (current === revision) data.value = result
  } catch (cause) {
    if (current === revision) error.value = cause
  } finally {
    if (current === revision) busy.value = false
  }
}
function select(value) {
  if (tab.value !== value) {
    tab.value = value
    page.value = 1
    load()
  }
}
function paginate(value) {
  page.value = value
  load()
}
async function inspect(workspace) {
  const current = ++revision
  controller?.abort()
  controller = new AbortController()
  busy.value = true
  error.value = null
  detail.value = null
  try {
    const result = await api.request(
      `/api/platform/workspaces/${workspace.uuid}?period=${period.value}`,
      { signal: controller.signal },
    )
    if (current === revision) detail.value = result
  } catch (cause) {
    if (current === revision) error.value = cause
  } finally {
    if (current === revision) busy.value = false
  }
}
function workspaceUsage(id) {
  usageWorkspace.value = String(id)
  tab.value = 'usage'
  page.value = 1
  load()
}
async function logout() {
  await api.request('/api/admin/logout', { method: 'POST' })
  api.resetCsrf()
  await router.replace('/admin/login')
}
watch(period, () => {
  page.value = 1
  load()
})
onBeforeUnmount(() => {
  revision++
  controller?.abort()
  data.value = detail.value = null
})
load()
</script>
<template>
  <section class="platform-page">
    <div class="page-heading platform-heading">
      <div>
        <h1>{{ t('platform.title') }}</h1>
        <p>{{ t('platform.intro') }}</p>
      </div>
      <button class="text-button" @click="logout">{{ t('platform.signOut') }}</button>
    </div>
    <nav class="platform-tabs" :aria-label="t('platform.title')">
      <button
        v-for="item in tabs"
        :key="item"
        class="button"
        :class="tab === item ? 'primary' : 'secondary'"
        :aria-current="tab === item ? 'page' : undefined"
        @click="select(item)"
      >
        {{ t('platform.' + item) }}
      </button>
    </nav>
    <div class="surface platform-controls">
      <label for="platform-period">{{ t('platform.period') }}</label
      ><select id="platform-period" v-model="period">
        <option value="24h">{{ t('platform.day') }}</option>
        <option value="7d">{{ t('platform.week') }}</option>
        <option value="30d">{{ t('platform.month') }}</option></select
      ><LoadingButton class="button secondary" :busy="busy" @click="load">{{
        t('platform.refresh')
      }}</LoadingButton>
      <form v-if="tab === 'workspaces'" @submit.prevent="paginate(1)">
        <label class="sr-only" for="platform-search">{{ t('platform.search') }}</label
        ><input
          id="platform-search"
          v-model="search"
          maxlength="100"
          :placeholder="t('platform.search')"
          type="search"
        /><button class="button secondary">{{ t('platform.find') }}</button>
      </form>
    </div>
    <form v-if="tab === 'usage'" class="platform-controls surface" @submit.prevent="paginate(1)">
      <label for="usage-workspace">{{ t('platform.workspaceId') }}</label
      ><input id="usage-workspace" v-model="usageWorkspace" type="number" min="1" /><button
        class="button secondary"
      >
        {{ t('platform.find') }}
      </button>
    </form>
    <p class="platform-note">{{ t('platform.diagnostic') }}</p>
    <p v-if="error" role="alert">{{ formatApiError(error) }}</p>
    <p v-if="busy" role="status">{{ t('flow.busy') }}</p>
    <template v-if="data && tab === 'overview'">
      <p v-if="!data.metricsEnabled" role="status">{{ t('platform.disabled') }}</p>
      <div class="platform-stats">
        <article
          v-for="key in ['workspaces', 'owners', 'projects', 'clients', 'tasks']"
          :key="key"
          class="surface"
        >
          <h2>{{ t('platform.' + (key === 'owners' ? 'owner' : key)) }}</h2>
          <strong>{{ number(key === 'workspaces' ? data.workspaces.total : data[key]) }}</strong>
        </article>
      </div>
      <p>
        {{ t('platform.active') }}: {{ number(data.workspaces.active) }} ·
        {{ t('platform.inactive') }}: {{ number(data.workspaces.inactive) }}
      </p>
      <p class="platform-note">{{ t('platform.activityNote') }}</p>
      <section class="surface platform-block">
        <h2>{{ t('platform.usage') }}</h2>
        <dl>
          <template
            v-for="key in [
              'requests',
              'responses_2xx',
              'responses_4xx',
              'responses_5xx',
              'responses_429',
              'average_ms',
              'maximum_ms',
            ]"
            :key="key"
            ><dt>
              {{
                key === 'requests'
                  ? 'API'
                  : key.startsWith('responses_')
                    ? key.replace('responses_', '')
                    : t('platform.' + (key === 'average_ms' ? 'average' : 'maximum'))
              }}
            </dt>
            <dd>{{ number(data.api[key]) }}</dd></template
          >
        </dl>
      </section>
    </template>
    <section v-if="detail" class="surface platform-block" aria-labelledby="workspace-details">
      <div class="platform-heading">
        <h2 id="workspace-details">{{ detail.workspace.name }} — {{ t('platform.details') }}</h2>
        <button class="text-button" @click="detail = null">{{ t('platform.close') }}</button>
      </div>
      <p v-for="owner in detail.owners" :key="owner.id">
        {{ t('platform.owner') }}: {{ owner.name }} · {{ owner.email }}
      </p>
      <p>
        {{ t('platform.created') }}: {{ date(detail.workspace.created_at) }} ·
        {{ t('platform.activity') }}: {{ date(detail.workspace.last_activity_at) }}
      </p>
      <dl>
        <template v-for="(value, key) in detail.counts" :key="key"
          ><dt>{{ t('platform.' + key) }}</dt>
          <dd>{{ number(value) }}</dd></template
        >
        <dt>{{ t('platform.tokens') }} ({{ t('platform.active') }} / {{ t('platform.total') }})</dt>
        <dd>{{ number(detail.tokens.active) }} / {{ number(detail.tokens.total) }}</dd>
        <dt>{{ t('platform.endpoints') }}</dt>
        <dd>{{ number(detail.webhookEndpoints) }}</dd>
      </dl>
      <h3>{{ t('platform.integrations') }}</h3>
      <p v-for="source in detail.integrations" :key="source.id">
        #{{ source.id }} · {{ source.name }}
      </p>
      <p v-if="!detail.integrations.length">—</p>
      <h3>{{ t('platform.limits') }}</h3>
      <dl>
        <template v-for="(value, key) in detail.limits" :key="key"
          ><dt>{{ t('platform.' + key) }}</dt>
          <dd>{{ typeof value === 'boolean' ? String(value) : number(value) }}</dd></template
        >
      </dl>
      <h3>{{ t('platform.usage') }}</h3>
      <button class="text-button" @click="workspaceUsage(detail.workspace.id)">
        {{ t('platform.usage') }} #{{ detail.workspace.id }}
      </button>
      <dl>
        <template v-for="(value, key) in detail.api" :key="key"
          ><dt>{{ key }}</dt>
          <dd>{{ number(value) }}</dd></template
        >
      </dl>
    </section>
    <section v-if="(tab === 'overview' && data) || detail" class="surface platform-block">
      <h2>Webhooks</h2>
      <dl>
        <template v-for="(value, key) in (detail || data).webhooks" :key="key"
          ><dt>{{ t('platform.' + key) }}</dt>
          <dd v-if="typeof value !== 'object'">{{ number(value) }}</dd>
          <dd v-else>
            <span v-for="(count, label) in value" :key="label" class="platform-count"
              >{{ t('platform.' + label) }}: {{ number(count) }}</span
            >
          </dd></template
        >
      </dl>
    </section>
    <div v-if="data?.data" class="surface platform-table" :aria-busy="busy" tabindex="0">
      <table v-if="tab === 'workspaces'">
        <caption class="sr-only">
          {{
            t('platform.workspaces')
          }}
        </caption>
        <thead>
          <tr>
            <th>{{ t('platform.workspaces') }}</th>
            <th>{{ t('platform.owner') }}</th>
            <th>{{ t('platform.activity') }} (UTC)</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in data.data" :key="item.id">
            <td>
              <button class="text-button" @click="inspect(item)">{{ item.name }}</button
              ><small>#{{ item.id }}</small>
            </td>
            <td>
              <p v-for="owner in item.owners" :key="owner.id">
                {{ owner.name }}<small>{{ owner.email }}</small>
              </p>
            </td>
            <td>{{ date(item.last_activity_at) }}</td>
          </tr>
        </tbody>
      </table>
      <table v-else-if="tab === 'usage'">
        <caption class="sr-only">
          {{
            t('platform.usage')
          }}
        </caption>
        <thead>
          <tr>
            <th>{{ t('platform.hour') }}</th>
            <th>
              {{ t('platform.workspaceId') }} / {{ t('platform.source') }} /
              {{ t('platform.token') }}
            </th>
            <th>{{ t('platform.route') }}</th>
            <th>API</th>
            <th>2xx / 4xx / 5xx / 429</th>
            <th>{{ t('platform.average') }} / {{ t('platform.maximum') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in data.data" :key="item.dimension_key">
            <td>{{ date(item.hour) }}</td>
            <td>
              {{ item.workspace_id ?? t('platform.unknown') }} /
              {{ item.integration_source_id ?? '—' }} / {{ item.integration_token_id ?? '—' }}
            </td>
            <td>
              <code>{{ item.method }} {{ item.route }}</code>
            </td>
            <td>{{ number(item.requests) }}</td>
            <td>
              {{ item.responses_2xx }} / {{ item.responses_4xx }} / {{ item.responses_5xx }} /
              {{ item.responses_429 }}
            </td>
            <td>{{ number(item.average_ms) }} / {{ number(item.maximum_ms) }}</td>
          </tr>
        </tbody>
      </table>
      <table v-else>
        <caption class="sr-only">
          {{
            t('platform.audit')
          }}
        </caption>
        <thead>
          <tr>
            <th>UTC</th>
            <th>{{ t('platform.administrator') }}</th>
            <th>{{ t('platform.action') }}</th>
            <th>{{ t('platform.object') }}</th>
            <th>{{ t('platform.result') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in data.data" :key="item.id">
            <td>{{ date(item.created_at) }}</td>
            <td>{{ item.administrator_id ?? 'CLI / guest' }}</td>
            <td>{{ item.action }}</td>
            <td>{{ item.object_type }} {{ item.object_id }}</td>
            <td>{{ item.result }}</td>
          </tr>
        </tbody>
      </table>
      <p v-if="!data.data.length">{{ t('platform.empty') }}</p>
    </div>
    <nav
      v-if="data?.last_page > 1"
      class="platform-pagination"
      :aria-label="t('platform.workspaces')"
    >
      <button class="button secondary" :disabled="busy || page <= 1" @click="paginate(page - 1)">
        {{ t('platform.previous') }}</button
      ><span>{{ page }} / {{ data.last_page }}</span
      ><button
        class="button secondary"
        :disabled="busy || page >= data.last_page"
        @click="paginate(page + 1)"
      >
        {{ t('platform.next') }}
      </button>
    </nav>
  </section>
</template>
<style scoped>
.platform-page {
  max-width: 1200px;
  margin: 0 auto;
  padding-bottom: 3rem;
}
.platform-heading,
.platform-tabs,
.platform-controls,
.platform-pagination,
form {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.platform-heading {
  justify-content: space-between;
}
.platform-tabs {
  margin-block: 20px;
}
.platform-controls,
.platform-block,
.platform-stats article {
  padding: 20px;
}
.platform-controls form {
  margin-left: auto;
}
.platform-controls input {
  min-width: 0;
  width: min(280px, 100%);
}
.platform-note,
small {
  color: var(--muted);
}
.platform-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
}
.platform-stats h2 {
  font-size: 1rem;
}
.platform-stats strong {
  font-size: 2rem;
}
.platform-block,
.platform-table {
  margin-block: 20px;
}
dl {
  display: grid;
  grid-template-columns: minmax(120px, 1fr) minmax(0, 2fr);
  gap: 10px 20px;
}
dd {
  margin: 0;
  overflow-wrap: anywhere;
}
.platform-count {
  display: inline-block;
  margin-right: 14px;
}
.platform-table {
  overflow-x: auto;
  padding: 12px;
}
table {
  min-width: 680px;
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}
th,
td {
  padding: 12px;
  border-bottom: 1px solid var(--border);
  vertical-align: top;
}
small {
  display: block;
}
code {
  overflow-wrap: anywhere;
}
.platform-pagination {
  justify-content: center;
}
@media (max-width: 600px) {
  .platform-controls form {
    margin-left: 0;
    width: 100%;
  }
  dl {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
