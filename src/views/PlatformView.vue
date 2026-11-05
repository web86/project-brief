<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t, intlLocale } from '../i18n/index.js'
import { useRequestFeedback } from '../services/requestFeedback.js'
import { planName, subscriptionDate } from '../services/subscription.js'
import LoadingButton from '../components/LoadingButton.vue'
import SubscriptionLoadError from '../components/SubscriptionLoadError.vue'
import PlatformWorkspaceView from './PlatformWorkspaceView.vue'

const route = useRoute()
const router = useRouter()
const tabs = ['overview', 'workspaces', 'usage', 'audit']
const tab = computed(() => (tabs.includes(route.query.section) ? route.query.section : 'overview'))
const workspaceId = computed(() =>
  tab.value === 'workspaces' && /^[a-f0-9-]{36}$/i.test(route.query.workspace || '')
    ? route.query.workspace
    : null,
)
const usageWorkspace = ref('')
const period = ref('24h')
const search = ref('')
const page = ref(1)
const data = ref(null)
const { busy, error, retrySeconds, run, cancel } = useRequestFeedback()
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
  if (workspaceId.value) return
  const query = new URLSearchParams({ period: period.value, page: String(page.value) })
  if (tab.value === 'workspaces') query.set('q', search.value)
  if (tab.value === 'usage' && usageWorkspace.value) query.set('workspace', usageWorkspace.value)
  if (tab.value === 'audit' && typeof route.query.workspaceFilter === 'string')
    query.set('workspace', route.query.workspaceFilter)
  const result = await run((signal) =>
    api.request('/api/platform/' + tab.value + '?' + query, { signal }),
  )
  if (result) data.value = result
  else if (error.value?.status === 401) router.replace('/admin/login')
}
function paginate(value) {
  page.value = value
  load()
}
watch(
  [tab, workspaceId, () => route.query.workspaceFilter],
  ([section], [previous] = []) => {
    cancel()
    if (section !== previous) {
      data.value = null
      page.value = 1
    }
    if (section === 'usage') usageWorkspace.value = String(route.query.workspaceFilter || '')
    load()
  },
  { immediate: true },
)
watch(period, () => paginate(1))
</script>
<template>
  <section class="platform-page">
    <PlatformWorkspaceView v-if="workspaceId" :key="workspaceId" :workspace-id="workspaceId" />
    <template v-else>
      <div class="page-heading platform-heading">
        <div>
          <h1>{{ t('platform.title') }}</h1>
          <p>{{ t('platform.intro') }}</p>
        </div>
      </div>
      <div class="surface platform-controls">
        <template v-if="tab !== 'workspaces'"
          ><label for="platform-period">{{ t('platform.period') }}</label
          ><select id="platform-period" v-model="period">
            <option value="24h">{{ t('platform.day') }}</option>
            <option value="7d">{{ t('platform.week') }}</option>
            <option value="30d">{{ t('platform.month') }}</option>
          </select></template
        ><LoadingButton
          class="button secondary"
          :busy="busy"
          :disabled="retrySeconds > 0"
          @click="load"
          >{{ t('platform.refresh') }}</LoadingButton
        >
        <form v-if="tab === 'workspaces'" @submit.prevent="paginate(1)">
          <label class="sr-only" for="platform-search">{{ t('platform.search') }}</label
          ><input
            id="platform-search"
            v-model="search"
            maxlength="100"
            :placeholder="t('platform.search')"
            type="search"
          /><button class="button secondary" :disabled="busy || retrySeconds > 0">
            {{ t('platform.find') }}
          </button>
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
      <SubscriptionLoadError :error="error" :seconds="retrySeconds" :retry="load" />
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
      <section v-if="tab === 'overview' && data" class="surface platform-block">
        <h2>Webhooks</h2>
        <dl>
          <template v-for="(value, key) in data.webhooks" :key="key"
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
        <table v-if="tab === 'workspaces'" class="workspace-list-table">
          <caption class="sr-only">
            {{
              t('platform.workspaces')
            }}
          </caption>
          <thead>
            <tr>
              <th>{{ t('platform.workspaces') }}</th>
              <th>{{ t('platform.owner') }}</th>
              <th>{{ t('subscriptionUx.subscription') }}</th>
              <th>{{ t('subscriptionUx.ends') }}</th>
              <th>{{ t('platform.projects') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in data.data" :key="item.id">
              <td>
                <RouterLink
                  class="text-button"
                  :to="{
                    path: '/platform',
                    query: { section: 'workspaces', workspace: item.uuid },
                  }"
                  :aria-label="t('subscriptionUx.openWorkspace', { name: item.name })"
                  >{{ item.name }}</RouterLink
                >
              </td>
              <td>
                <p v-for="owner in item.owners" :key="owner.id">
                  {{ owner.name }}<small>{{ owner.email }}</small>
                </p>
              </td>
              <td>
                {{ planName(item.subscription)
                }}<small v-if="item.subscription">{{
                  t('account.subscriptionPhase.' + item.subscription.phase)
                }}</small>
              </td>
              <td>{{ item.subscription ? subscriptionDate(item.subscription.endsAt) : '—' }}</td>
              <td>{{ number(item.projectCount) }}</td>
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
        <button
          class="button secondary"
          :disabled="busy || retrySeconds > 0 || page <= 1"
          @click="paginate(page - 1)"
        >
          {{ t('platform.previous') }}</button
        ><span>{{ page }} / {{ data.last_page }}</span
        ><button
          class="button secondary"
          :disabled="busy || retrySeconds > 0 || page >= data.last_page"
          @click="paginate(page + 1)"
        >
          {{ t('platform.next') }}
        </button>
      </nav>
    </template>
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
.workspace-list-table td {
  overflow-wrap: anywhere;
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
