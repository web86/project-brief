<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import { useRequestFeedback } from '../services/requestFeedback.js'
import { subscriptionDate } from '../services/subscription.js'
import SubscriptionLoadError from '../components/SubscriptionLoadError.vue'
import SubscriptionOverview from '../components/SubscriptionOverview.vue'
import SubscriptionQuotas from '../components/SubscriptionQuotas.vue'
import SubscriptionChangeWizard from '../components/SubscriptionChangeWizard.vue'

const props = defineProps({ workspaceId: { type: String, required: true } })
const route = useRoute()
const router = useRouter()
const pages = ['overview', 'subscription', 'resources', 'diagnostics']
const current = computed(() => (pages.includes(route.query.view) ? route.query.view : 'overview'))
const period = ref('24h')
const wizard = ref(false)
const saved = ref(false)
const views = ref({})
const header = ref(null)
const { busy, error, retrySeconds, run, cancel } = useRequestFeedback()
const data = computed(() => views.value[current.value])
const label = (key) => t('subscriptionUx.' + key)
const location = (view) => ({
  path: '/platform',
  query: { section: 'workspaces', workspace: props.workspaceId, view },
})

async function load() {
  const view = current.value
  const query = new URLSearchParams({
    view,
    ...(view === 'diagnostics' ? { period: period.value } : {}),
  })
  const result = await run((signal) =>
    api.request('/api/platform/workspaces/' + props.workspaceId + '?' + query, { signal }),
  )
  if (result) {
    views.value[view] = result
    header.value = result.workspace
  } else if (error.value?.status === 401) {
    router.replace('/admin/login')
  }
}
watch(
  current,
  () => {
    wizard.value = false
    cancel()
    if (!data.value) load()
  },
  { immediate: true },
)
watch(period, load)
function assigned() {
  wizard.value = false
  saved.value = true
  delete views.value.resources
  toast.success(() => label('assigned'))
  load()
}
</script>

<template>
  <section class="platform-workspace">
    <RouterLink :to="{ path: '/platform', query: { section: 'workspaces' } }" class="text-button">{{
      t('platform.workspaces')
    }}</RouterLink>
    <h1 id="workspace-details">{{ header?.name || label('workspace') }}</h1>
    <nav class="workspace-tabs" :aria-label="label('workspace')">
      <RouterLink
        v-for="view in pages"
        :key="view"
        :to="location(view)"
        class="button secondary"
        :aria-current="view === current ? 'page' : undefined"
        >{{ label(view) }}</RouterLink
      >
    </nav>
    <SubscriptionLoadError :error="error" :seconds="retrySeconds" :retry="load" />
    <p v-if="busy" role="status">{{ t('nav.loading') }}</p>
    <template v-if="data">
      <section v-if="current === 'overview'" class="surface workspace-block">
        <h2>{{ label('overview') }}</h2>
        <p v-for="owner in data.owners" :key="owner.id">
          {{ t('platform.owner') }} · {{ owner.name }} · {{ owner.email }}
        </p>
        <dl>
          <template v-for="(value, key) in data.counts" :key="key">
            <dt>{{ t('platform.' + key) }}</dt>
            <dd>{{ value }}</dd>
          </template>
        </dl>
        <RouterLink class="button primary" :to="location('subscription')">{{
          label('subscription')
        }}</RouterLink>
      </section>
      <template v-else-if="current === 'subscription'">
        <p v-if="saved" role="status">{{ label('assigned') }}</p>
        <SubscriptionOverview :subscription="data.subscription" :usage="data.usage">
          <button
            v-if="
              route.meta.canManagePlans &&
              (data.subscription
                ? data.subscription.phase !== 'compatibility'
                : data.initialAssignmentStatus === 'ready')
            "
            class="button primary change-plan"
            :disabled="busy || retrySeconds > 0"
            @click="wizard = true"
          >
            {{ label(data.subscription ? 'changePlan' : 'assign') }}
          </button>
          <p v-else-if="data.subscription?.phase === 'compatibility'">
            {{ label('compatibility') }}
          </p>
          <p v-else-if="route.meta.canManagePlans && !data.subscription">
            {{
              data.initialAssignmentStatus === 'onboarding_required'
                ? label('initialOnboardingRequired')
                : t('manualSubscription.unavailable')
            }}
          </p>
        </SubscriptionOverview>
        <SubscriptionChangeWizard
          v-if="wizard"
          :workspace-id="workspaceId"
          :initial="!data.subscription"
          @close="wizard = false"
          @assigned="assigned"
        />
      </template>
      <section v-else-if="current === 'resources'" class="surface workspace-block">
        <h2>{{ label('resources') }}</h2>
        <SubscriptionQuotas
          v-if="data.subscription"
          :limits="data.subscription.limits"
          :usage="data.usage"
        />
        <p>{{ label('holds') }}</p>
        <RouterLink
          v-if="route.meta.canManagePlans"
          class="button secondary"
          :to="'/platform/plans/inventory/' + workspaceId"
        >
          {{ t('inventory.title') }}
        </RouterLink>
      </section>
      <section v-else class="surface workspace-block">
        <h2>{{ label('diagnostics') }}</h2>
        <label
          >{{ t('platform.period') }}
          <select v-model="period" :disabled="busy || retrySeconds > 0">
            <option value="24h">{{ t('platform.day') }}</option>
            <option value="7d">{{ t('platform.week') }}</option>
            <option value="30d">{{ t('platform.month') }}</option>
          </select>
        </label>
        <div class="workspace-diagnostics">
          <section>
            <h3>API</h3>
            <dl>
              <dt>{{ t('platform.tokens') }}</dt>
              <dd>{{ data.tokens.active }} / {{ data.tokens.total }}</dd>
              <template v-for="(value, key) in data.api" :key="key"
                ><dt>{{ key }}</dt>
                <dd>{{ value }}</dd></template
              >
              <template v-for="(value, key) in data.limits" :key="key"
                ><dt>{{ t('platform.' + key) }}</dt>
                <dd>{{ value }}</dd></template
              >
            </dl>
            <RouterLink
              :to="{
                path: '/platform',
                query: { section: 'usage', workspaceFilter: data.workspace.id },
              }"
              >{{ t('platform.usage') }}</RouterLink
            >
          </section>
          <section>
            <h3>Webhooks</h3>
            <dl>
              <dt>{{ t('platform.endpoints') }}</dt>
              <dd>{{ data.webhookEndpoints }}</dd>
              <template v-for="(value, key) in data.webhooks" :key="key"
                ><dt>{{ t('platform.' + key) }}</dt>
                <dd>
                  {{
                    typeof value === 'object'
                      ? Object.entries(value)
                          .map(([status, count]) => t('platform.' + status) + ' ' + count)
                          .join(' · ')
                      : value
                  }}
                </dd>
              </template>
            </dl>
          </section>
        </div>
        <details>
          <summary>{{ label('technicalDetails') }}</summary>
          <p>
            {{ t('platform.workspaceId') }} · {{ data.workspace.id }} · {{ data.workspace.uuid }}
          </p>
          <p v-if="data.workspace.created_at">
            {{ t('platform.created') }} ·
            {{ subscriptionDate(data.workspace.created_at.replace(' ', 'T') + 'Z') }}
          </p>
          <p v-for="source in data.integrations" :key="source.id">
            {{ source.name }} · {{ source.id }}
          </p>
          <RouterLink
            :to="{ path: '/platform', query: { section: 'audit', workspaceFilter: workspaceId } }"
            >{{ t('platform.audit') }}</RouterLink
          >
        </details>
      </section>
    </template>
  </section>
</template>

<style scoped>
.platform-workspace {
  display: grid;
  gap: 14px;
}
.platform-workspace h1 {
  margin: 0;
  overflow-wrap: anywhere;
}
.workspace-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.workspace-tabs [aria-current] {
  border-color: var(--accent, var(--link));
  color: var(--link);
}
.workspace-block {
  padding: clamp(16px, 4vw, 24px);
  min-width: 0;
}
.workspace-block h2 {
  margin-top: 0;
}
.workspace-block dl {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px 14px;
}
.workspace-block dd {
  margin: 0;
}
.workspace-block p,
.workspace-block dt {
  overflow-wrap: anywhere;
}
.workspace-diagnostics {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 250px), 1fr));
  gap: 20px;
  margin-bottom: 16px;
}
.change-plan {
  justify-self: start;
}
</style>
