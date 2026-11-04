<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import { api } from '../api/client.js'
import { t, locale } from '../i18n/index.js'
import LoadingButton from '../components/LoadingButton.vue'
import BaseDialog from '../components/BaseDialog.vue'
import PlanEntitlements from '../components/PlanEntitlements.vue'
import {
  createPlanRequests,
  newPlanConfiguration,
  normalizedPlan,
} from '../services/planManagement.js'
const route = useRoute(),
  router = useRouter()
const l = (key) => t('planManagement.' + key)
const clone = (data) => JSON.parse(JSON.stringify(data))
const translationLanguage = ref('en')
const mode = computed(() => route.meta.planSection || 'catalog')
const busy = ref(false),
  error = ref(''),
  notice = ref(''),
  definitions = ref([])
const catalog = ref([]),
  meta = ref({ page: 1, lastPage: 1 }),
  history = ref([]),
  historyMeta = ref({ page: 1, lastPage: 1 })
const filters = ref({ q: '', status: '', visibility: '', sort: 'order', showSystem: false })
const config = ref(null),
  code = ref(''),
  version = ref(null),
  plan = ref(null),
  metadata = ref(null)
const policies = ref([]),
  policy = ref(null),
  currentPolicy = ref(null),
  supported = ref(null)
const versions = ref([]),
  versionsMeta = ref({ page: 0, lastPage: 1 }),
  rules = ref([]),
  rule = ref(null)
const copy = ref({ fromVersionId: '', toVersionId: '' }),
  preview = ref(null),
  previewKind = ref(null)
const baseline = ref('')
const dirty = computed(() => baseline.value && baseline.value !== fingerprint())
const readOnly = computed(
  () => version.value?.state === 'published' || plan.value?.status === 'archived',
)
const policyReadOnly = computed(() => policy.value?.state === 'published')
const send = createPlanRequests(api.request)
let generation = 0,
  controller,
  internalNavigation = false
const fingerprint = () =>
  JSON.stringify({
    config: config.value,
    code: code.value,
    policy: policy.value,
    rule: rule.value,
    metadata: metadata.value,
  })
function clean() {
  baseline.value = fingerprint()
}
function savedParts(keys) {
  const previous = JSON.parse(baseline.value || fingerprint())
  const current = JSON.parse(fingerprint())
  for (const key of keys) previous[key] = current[key]
  baseline.value = JSON.stringify(previous)
}
async function goSaved(path, replace = false) {
  internalNavigation = true
  try {
    await router[replace ? 'replace' : 'push'](path)
  } finally {
    internalNavigation = false
  }
}
function confirmLeave() {
  return !dirty.value || window.confirm(l('leave'))
}
function errorText(cause) {
  if (cause.code === 'plan_dependencies') return l('dependency')
  if (cause.status === 409) return l('conflict')
  if ([422, 428].includes(cause.status)) return l('invalid')
  if ([401, 403].includes(cause.status)) return l('forbidden')
  return l('error')
}
async function run(operation) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await operation()
  } catch (cause) {
    error.value = errorText(cause)
  } finally {
    busy.value = false
  }
}
function name(row) {
  return row?.translations?.[locale.value]?.name || row?.translations?.en?.name || row?.code || ''
}
function priceLabel(row) {
  const price = row?.price
  if (price?.amount_minor == null || !price.currency) return t('preparation.price')
  return new Intl.NumberFormat(locale.value, {
    style: 'currency',
    currency: price.currency,
    maximumFractionDigits: price.scale ?? 2,
  }).format(price.amount_minor / 10 ** (price.scale ?? 2))
}
function limitLabel(value) {
  return value?.kind === 'unlimited' ? t('preparation.unlimited') : (value?.value ?? value ?? '—')
}
function versionLabel(row) {
  if (!row) return '—'
  return `${name(row)} · ${row.code} · v${row.number}`
}
function versionPath(row) {
  return `/api/platform/plans/${row.planId}/versions/${row.id}`
}
function policyPath(row) {
  return `/api/platform/plan-settings/drafts/${row.id}`
}
function activePath() {
  return previewKind.value === 'policy' ? policyPath(policy.value) : versionPath(version.value)
}
function showValue(value) {
  return value == null ? '—' : typeof value === 'object' ? JSON.stringify(value) : String(value)
}
async function publishedMore() {
  const result = await api.request(
    `/api/platform/published-plan-versions?page=${versionsMeta.value.page + 1}`,
  )
  versions.value.push(...result.data)
  versionsMeta.value = result.meta
}
async function load(page = 1) {
  translationLanguage.value = 'en'
  const current = ++generation
  controller?.abort()
  controller = new AbortController()
  busy.value = true
  error.value = ''
  notice.value = ''
  baseline.value = ''
  preview.value = null
  config.value = version.value = plan.value = metadata.value = policy.value = rule.value = null
  try {
    const get = (path) => api.request(path, { signal: controller.signal })
    const registry = await get('/api/platform/entitlement-definitions')
    if (current !== generation) return
    definitions.value = registry.data
    if (mode.value === 'editor') {
      if (route.params.planId === 'new') {
        config.value = newPlanConfiguration(definitions.value)
        code.value = ''
      } else {
        const [detail, resource, events] = await Promise.all([
          get(`/api/platform/plans/${route.params.planId}`),
          get(`/api/platform/plans/${route.params.planId}/versions/${route.params.versionId}`),
          get(`/api/platform/plans/${route.params.planId}/history`),
        ])
        if (current !== generation) return
        plan.value = detail.data
        version.value = resource.data
        config.value = clone(resource.data.configuration)
        code.value = detail.data.code
        metadata.value = { visibility: detail.data.visibility, sortOrder: detail.data.sortOrder }
        history.value = events.data
        historyMeta.value = events.meta
        catalog.value = detail.data.versions
        meta.value = detail.meta
      }
    } else if (mode.value === 'catalog') {
      const query = new URLSearchParams({
        ...filters.value,
        showSystem: filters.value.showSystem ? '1' : '0',
        page: String(page),
      })
      const result = await get(`/api/platform/plans?${query}`)
      if (current !== generation) return
      catalog.value = result.data
      meta.value = result.meta
    } else {
      const published = await get('/api/platform/published-plan-versions')
      if (current !== generation) return
      versions.value = published.data
      versionsMeta.value = published.meta
      if (mode.value === 'policies') {
        const result = await get(`/api/platform/plan-settings?page=${page}`)
        if (current !== generation) return
        policies.value = result.data
        meta.value = result.meta
        supported.value = result.supportedPolicy
        currentPolicy.value = result.currentPolicyId
      } else {
        const result = await get(`/api/platform/plan-transition-rules?page=${page}`)
        if (current !== generation) return
        rules.value = result.data
        meta.value = result.meta
      }
    }
    clean()
  } catch (cause) {
    if (current === generation && cause.name !== 'AbortError') error.value = errorText(cause)
  } finally {
    if (current === generation) busy.value = false
  }
}
async function navigate(path) {
  if (busy.value) return
  await router.push(path)
}
async function savePlan() {
  await run(async () => {
    const body = normalizedPlan(config.value)
    const result = version.value
      ? await send(versionPath(version.value), 'PUT', body, version.value.revision)
      : await send('/api/platform/plans', 'POST', { code: code.value, ...body })
    version.value = result.data
    config.value = clone(result.data.configuration)
    savedParts(['config', 'code'])
    notice.value = l('saved')
    if (route.params.planId === 'new')
      await goSaved(`/platform/plans/${result.data.planId}/versions/${result.data.id}`, true)
    else {
      const resultPlan = await api.request(`/api/platform/plans/${version.value.planId}`)
      plan.value = resultPlan.data
    }
  })
}
async function newDraft(row, sourceVersionId = null) {
  if (!confirmLeave()) return
  await run(async () => {
    const result = await send(
      `/api/platform/plans/${row.id}/draft`,
      'POST',
      sourceVersionId ? { sourceVersionId } : {},
      row.revision,
    )
    await goSaved(`/platform/plans/${row.id}/versions/${result.data.id}`)
  })
}
async function archive(row) {
  if (!window.confirm(l('archiveConfirm'))) return
  await run(async () => {
    await send(
      `/api/platform/plans/${row.id}/${row.status === 'archived' ? 'unarchive' : 'archive'}`,
      'POST',
      {},
      row.revision,
    )
  })
  if (!error.value) await load(meta.value.page)
}
async function saveMetadata() {
  await run(async () => {
    const result = await send(
      `/api/platform/plans/${plan.value.id}`,
      'PATCH',
      metadata.value,
      plan.value.revision,
    )
    plan.value = result.data
    config.value.visibility = result.data.visibility
    savedParts(['metadata'])
    const saved = JSON.parse(baseline.value)
    saved.config.visibility = config.value.visibility
    baseline.value = JSON.stringify(saved)
    notice.value = l('saved')
  })
}
async function requestPreview(kind) {
  if (dirty.value) {
    error.value = l('unsaved')
    return
  }
  previewKind.value = kind
  await run(async () => {
    const row = kind === 'policy' ? policy.value : version.value
    preview.value = (
      await send(activePath() + '/previews', 'POST', { policy: 'keep_existing' }, row.revision)
    ).data
  })
}
async function publish() {
  await run(async () => {
    const row = previewKind.value === 'policy' ? policy.value : version.value
    const result = await send(
      activePath() + '/publish',
      'POST',
      {
        policy: 'keep_existing',
        previewId: preview.value.id,
        contentHash: preview.value.contentHash,
      },
      row.revision,
    )
    if (previewKind.value === 'policy') {
      policy.value = result.data
      currentPolicy.value = result.data.id
      policies.value = [result.data, ...policies.value.filter((p) => p.id !== result.data.id)]
    } else {
      version.value = result.data
      const [detail, events] = await Promise.all([
        api.request(`/api/platform/plans/${result.data.planId}`),
        api.request(`/api/platform/plans/${result.data.planId}/history`),
      ])
      plan.value = detail.data
      catalog.value = detail.data.versions
      history.value = events.data
      historyMeta.value = events.meta
    }
    preview.value = null
    clean()
    notice.value = l('saved')
  })
}
function selectPolicy(row, copyPublished = false) {
  if (!confirmLeave()) return
  policy.value = row
    ? clone(row)
    : {
        id: null,
        state: 'draft',
        configuration: {
          trialDays: 7,
          graceDays: 7,
          trialVersionId: '',
          fallbackVersionId: '',
          ...clone(supported.value),
        },
      }
  if (copyPublished) {
    policy.value.id = null
    policy.value.state = 'draft'
    delete policy.value.revision
  }
  clean()
}
async function savePolicy() {
  await run(async () => {
    const result = await send(
      policy.value.id ? policyPath(policy.value) : '/api/platform/plan-settings/drafts',
      policy.value.id ? 'PUT' : 'POST',
      policy.value.configuration,
      policy.value.revision,
    )
    policy.value = result.data
    policies.value = [result.data, ...policies.value.filter((p) => p.id !== result.data.id)]
    clean()
    notice.value = l('saved')
  })
}
function selectRule(row = null) {
  if (!confirmLeave()) return
  rule.value = row
    ? clone(row)
    : {
        id: null,
        sourceVersionId: '',
        targetVersionId: '',
        initiator: 'platform',
        approval: 'manual',
        timing: 'immediate',
        enabled: false,
        overagePolicy: { active: 'select_and_suspend', storage: 'read_only' },
      }
  clean()
}
async function saveRule() {
  await run(async () => {
    const { id, revision, ...body } = rule.value
    await send(
      '/api/platform/plan-transition-rules' + (id ? '/' + id : ''),
      id ? 'PATCH' : 'POST',
      body,
      revision,
    )
    clean()
    notice.value = l('saved')
  })
  if (!error.value) await load(meta.value.page)
}
async function copyPreview() {
  previewKind.value = 'copy'
  await run(async () => {
    preview.value = (
      await send('/api/platform/plan-transition-rules/copy-previews', 'POST', copy.value)
    ).data
  })
}
async function confirmCopy() {
  await run(async () => {
    await send('/api/platform/plan-transition-rules/copies', 'POST', {
      previewId: preview.value.id,
      contentHash: preview.value.contentHash,
    })
    preview.value = null
    const updated = await api.request(`/api/platform/plan-transition-rules?page=${meta.value.page}`)
    rules.value = updated.data
    meta.value = updated.meta
    notice.value = l('copied')
  })
}
async function historyPage(page) {
  await run(async () => {
    const result = await api.request(`/api/platform/plans/${plan.value.id}/history?page=${page}`)
    history.value = result.data
    historyMeta.value = result.meta
  })
}
async function versionsPage(page) {
  await run(async () => {
    const result = await api.request(`/api/platform/plans/${plan.value.id}?page=${page}`)
    catalog.value = result.data.versions
    meta.value = result.meta
  })
}
function unload(event) {
  if (dirty.value) {
    event.preventDefault()
    event.returnValue = ''
  }
}
onBeforeRouteLeave(() => internalNavigation || (!busy.value && confirmLeave()))
onBeforeRouteUpdate(() => internalNavigation || (!busy.value && confirmLeave()))
watch(
  () => route.fullPath,
  () => load(),
)
onMounted(() => {
  window.addEventListener('beforeunload', unload)
  load()
})
onBeforeUnmount(() => {
  generation++
  controller?.abort()
  window.removeEventListener('beforeunload', unload)
})
</script>
<template>
  <section class="platform-page plans-page">
    <header class="page-heading">
      <div>
        <h1>{{ l('title') }}</h1>
        <p>{{ l('intro') }}</p>
      </div>
      <RouterLink class="text-button" to="/platform">{{ l('diagnostics') }}</RouterLink>
    </header>
    <p v-if="error" class="plans-error surface" role="alert" tabindex="-1">{{ error }}</p>
    <p v-if="notice" role="status">{{ notice }}</p>
    <p v-if="busy" role="status">{{ t('flow.busy') }}</p>
    <template v-if="mode === 'catalog'">
      <form class="surface plans-controls" @submit.prevent="load()">
        <label class="plans-system-check"
          ><input v-model="filters.showSystem" type="checkbox" />{{
            t('preparation.showSystem')
          }}</label
        >
        <label>{{ l('search') }}<input v-model="filters.q" type="search" maxlength="100" /></label
        ><label
          >{{ l('status')
          }}<select v-model="filters.status">
            <option value="">{{ l('all') }}</option>
            <option v-for="state in ['draft', 'published', 'archived']" :key="state" :value="state">
              {{ l('states.' + state) }}
            </option>
          </select></label
        ><label
          >{{ l('visibility')
          }}<select v-model="filters.visibility">
            <option value="">{{ l('all') }}</option>
            <option v-for="value in ['public', 'hidden']" :key="value" :value="value">
              {{ l('states.' + value) }}
            </option>
          </select></label
        ><label
          >{{ l('sort')
          }}<select v-model="filters.sort">
            <option
              v-for="value in ['order', 'code', 'status', 'newest']"
              :key="value"
              :value="value"
            >
              {{ l(value) }}
            </option>
          </select></label
        ><LoadingButton type="submit" class="button secondary" :busy="busy">{{
          l('find')
        }}</LoadingButton
        ><button
          type="button"
          class="button primary"
          :disabled="busy"
          @click="navigate('/platform/plans/new/versions/new')"
        >
          {{ l('create') }}
        </button>
      </form>
      <p v-if="!busy && !catalog.length">{{ l('empty') }}</p>
      <div class="plans-catalog">
        <article v-for="row in catalog" :key="row.id" class="surface plans-card">
          <header>
            <h2>{{ name(row.versions[0]) || row.code }}</h2>
            <span class="badge">{{ l('states.' + row.status) }}</span>
          </header>
          <p>
            <code>{{ row.code }}</code> · {{ l('states.' + row.visibility) }}
          </p>
          <p v-if="row.versions[0]?.accessClass === 'compatibility'" class="inventory-notice">
            {{ t('preparation.system') }}
          </p>
          <p v-else>{{ l('price') }}: {{ priceLabel(row.versions[0]) }}</p>
          <dl class="plans-key-limits">
            <div>
              <dt>{{ l('seats') }}</dt>
              <dd>{{ limitLabel(row.versions[0]?.developerSeats?.included) }}</dd>
            </div>
            <div v-for="[key, value] in Object.entries(row.versions[0]?.limits || {})" :key="key">
              <dt>{{ l('featureNames.' + key) }}</dt>
              <dd>{{ limitLabel(value) }}</dd>
            </div>
          </dl>
          <p
            v-if="Object.values(row.versions[0]?.limits || {}).some((v) => v?.kind === 'unlimited')"
          >
            <small>{{ t('preparation.technical') }}</small>
          </p>
          <p>{{ l('version') }}: {{ row.versions.find((v) => v.current)?.number ?? '—' }}</p>
          <div class="plans-actions">
            <button
              v-for="v in row.versions.filter((v) => v.state === 'draft' || v.current)"
              :key="v.id"
              class="button primary"
              :disabled="busy"
              @click="navigate(`/platform/plans/${row.id}/versions/${v.id}`)"
            >
              {{ l('edit') }} v{{ v.number }} · {{ l('states.' + v.state) }}
            </button>
            <details class="plans-more-actions">
              <summary>{{ l('moreActions') }}</summary>
              <button
                v-if="row.status === 'published' && !row.versions.some((v) => v.state === 'draft')"
                class="button secondary"
                :disabled="busy"
                @click="newDraft(row)"
              >
                {{ l('newDraft') }}</button
              ><button class="text-button" :disabled="busy" @click="archive(row)">
                {{ l(row.status === 'archived' ? 'unarchive' : 'archive') }}
              </button>
            </details>
          </div>
        </article>
      </div>
    </template>
    <template v-if="mode === 'editor' && config">
      <form class="surface plans-editor" @submit.prevent="savePlan">
        <div class="plans-language-tabs" role="group" :aria-label="l('translations')">
          <button
            v-for="lang in ['ru', 'en', 'tr', 'zh-CN']"
            :key="lang"
            type="button"
            class="button secondary"
            :aria-pressed="translationLanguage === lang"
            @click="translationLanguage = lang"
          >
            {{ lang.toUpperCase() }}
          </button>
        </div>
        <fieldset :disabled="readOnly || busy">
          <legend>{{ l('basics') }}</legend>
          <label
            >{{ l('code')
            }}<input
              v-model="code"
              :readonly="!!version"
              pattern="[a-z][a-z0-9_-]{1,63}"
              maxlength="64"
              required /></label
          ><label
            >{{ l('accessClass')
            }}<select v-model="config.accessClass">
              <option
                v-for="value in config.accessClass === 'compatibility'
                  ? ['compatibility']
                  : ['free', 'paid']"
                :key="value"
                :value="value"
              >
                {{ l('states.' + value) }}
              </option>
            </select></label
          ><label
            >{{ l('visibility')
            }}<select v-model="config.visibility">
              <option v-for="value in ['public', 'hidden']" :key="value" :value="value">
                {{ l('states.' + value) }}
              </option>
            </select></label
          >
          <section>
            <h2>{{ l('translations') }}</h2>
            <div class="plans-feature-grid">
              <div
                v-for="lang in ['ru', 'en', 'tr', 'zh-CN']"
                v-show="translationLanguage === lang"
                :key="lang"
                class="plans-translation-panel"
              >
                <h3>{{ lang }}</h3>
                <label
                  >{{ l('name') }} ({{ lang }})<input
                    v-model="config.translations[lang].name"
                    @invalid="translationLanguage = lang"
                    maxlength="160"
                    required /></label
                ><label
                  >{{ l('description') }} ({{ lang }})<textarea
                    v-model="config.translations[lang].description"
                    maxlength="4000"
                    rows="3"
                  />
                </label>
              </div>
            </div>
          </section>
          <section>
            <h2>{{ l('price') }}</h2>
            <p>{{ l('priceHint') }}</p>
            <div class="plans-feature-grid">
              <label
                >{{ l('amount')
                }}<input
                  v-model.number="config.price.amountMinor"
                  type="number"
                  min="0"
                  step="1" /></label
              ><label
                >{{ l('currency')
                }}<input
                  v-model="config.price.currency"
                  pattern="[A-Za-z]{3}"
                  maxlength="3" /></label
              ><label
                >{{ l('scale')
                }}<input
                  v-model.number="config.price.scale"
                  type="number"
                  min="0"
                  max="4"
                  required /></label
              ><label
                >{{ l('interval')
                }}<select v-model="config.price.interval">
                  <option :value="null">{{ l('unconfigured') }}</option>
                  <option
                    v-for="value in ['month', 'year', 'custom_days']"
                    :key="value"
                    :value="value"
                  >
                    {{ l(value) }}
                  </option>
                </select></label
              >
            </div>
          </section>
          <section>
            <h2>{{ l('seats') }}</h2>
            <p>{{ l('seatHint') }}</p>
            <div class="plans-feature-grid">
              <label
                >{{ l('included')
                }}<input
                  v-model.number="config.developerSeats.included"
                  type="number"
                  min="0"
                  max="50"
                  required /></label
              ><label
                >{{ l('hardMax')
                }}<input
                  v-model.number="config.developerSeats.hardMax"
                  type="number"
                  :min="config.developerSeats.included"
                  max="50"
                  required /></label
              ><label class="plans-check"
                ><input v-model="config.developerSeats.allowExtra" type="checkbox" />{{
                  l('allowExtra')
                }}</label
              >
            </div>
          </section>
          <section>
            <h2>{{ l('features') }}</h2>
            <PlanEntitlements :definitions="definitions" :configuration="config" />
          </section>
        </fieldset>
        <div class="plans-actions plans-save-bar">
          <span role="status">{{
            dirty
              ? l('unsaved')
              : readOnly
                ? l('readOnly')
                : version
                  ? l('saved')
                  : l('states.draft')
          }}</span>
          <LoadingButton type="submit" v-if="!readOnly" class="button primary" :busy="busy">{{
            l('save')
          }}</LoadingButton
          ><button
            v-if="version && !readOnly"
            type="button"
            class="button secondary"
            :disabled="busy || !!dirty"
            @click="requestPreview('plan')"
          >
            {{ l('preview') }}</button
          ><button
            v-if="plan?.status === 'published' && version?.state === 'published' && !plan.hasDraft"
            type="button"
            class="button secondary"
            :disabled="busy"
            @click="newDraft(plan, version.id)"
          >
            {{ l('newDraft') }}</button
          ><button
            type="button"
            class="text-button"
            :disabled="busy"
            @click="confirmLeave() && load()"
          >
            {{ l('reload') }}
          </button>
        </div>
      </form>
      <form v-if="metadata" class="surface plans-editor" @submit.prevent="saveMetadata">
        <h2>{{ l('catalogMetadata') }}</h2>
        <label
          >{{ l('visibility')
          }}<select v-model="metadata.visibility">
            <option value="public">{{ l('states.public') }}</option>
            <option value="hidden">{{ l('states.hidden') }}</option>
          </select></label
        ><label
          >{{ l('order')
          }}<input
            v-model.number="metadata.sortOrder"
            type="number"
            min="-100000"
            max="100000" /></label
        ><LoadingButton type="submit" class="button secondary" :busy="busy">{{
          l('saveMetadata')
        }}</LoadingButton>
      </form>
      <section v-if="plan" class="surface plans-editor">
        <h2>{{ l('version') }}</h2>
        <div class="plans-actions">
          <button
            v-for="v in catalog"
            :key="v.id"
            class="button secondary"
            :disabled="busy"
            @click="navigate(`/platform/plans/${plan.id}/versions/${v.id}`)"
          >
            v{{ v.number }} · {{ l('states.' + v.state) }}
          </button>
        </div>
        <div class="plans-actions">
          <button
            class="text-button"
            :disabled="busy || meta.page <= 1"
            @click="versionsPage(meta.page - 1)"
          >
            {{ l('previous') }}</button
          ><span>{{ l('page') }} {{ meta.page }}</span
          ><button
            class="text-button"
            :disabled="busy || meta.page >= meta.lastPage"
            @click="versionsPage(meta.page + 1)"
          >
            {{ l('next') }}
          </button>
        </div>
        <h2>{{ l('history') }}</h2>
        <ul>
          <li v-for="event in history" :key="event.uuid">
            {{ event.created_at }} · {{ l('actionNames.' + event.action) }} ·
            {{ event.before_revision }} →
            {{ event.after_revision }}
          </li>
        </ul>
        <div class="plans-actions">
          <button
            class="text-button"
            :disabled="busy || historyMeta.page <= 1"
            @click="historyPage(historyMeta.page - 1)"
          >
            {{ l('previous') }}</button
          ><span>{{ historyMeta.page }}</span
          ><button
            class="text-button"
            :disabled="busy || historyMeta.page >= historyMeta.lastPage"
            @click="historyPage(historyMeta.page + 1)"
          >
            {{ l('next') }}
          </button>
        </div>
      </section>
    </template>
    <template v-if="mode === 'policies'">
      <p>{{ l('policyHint') }}</p>
      <button class="button primary" :disabled="busy || !supported" @click="selectPolicy(null)">
        {{ l('newPolicy') }}
      </button>
      <div class="plans-catalog">
        <article v-for="row in policies" :key="row.id" class="surface plans-card">
          <h2>{{ l('version') }} {{ row.number }} · {{ l('states.' + row.state) }}</h2>
          <p v-if="row.id === currentPolicy">{{ l('current') }}</p>
          <div class="plans-actions">
            <button class="button secondary" :disabled="busy" @click="selectPolicy(row)">
              {{ l('edit') }}</button
            ><button
              v-if="row.state === 'published'"
              class="button secondary"
              :disabled="busy"
              @click="selectPolicy(row, true)"
            >
              {{ l('clone') }}
            </button>
          </div>
        </article>
      </div>
      <form v-if="policy" class="surface plans-editor" @submit.prevent="savePolicy">
        <p>
          {{
            dirty
              ? l('unsaved')
              : policyReadOnly
                ? l('readOnly')
                : policy.id
                  ? l('saved')
                  : l('states.draft')
          }}
        </p>
        <fieldset :disabled="busy || policyReadOnly">
          <legend>{{ l('policies') }}</legend>
          <div class="plans-feature-grid">
            <label
              >{{ l('trialDays')
              }}<input
                v-model.number="policy.configuration.trialDays"
                type="number"
                min="1"
                max="366"
                required /></label
            ><label
              >{{ l('graceDays')
              }}<input
                v-model.number="policy.configuration.graceDays"
                type="number"
                min="0"
                max="366"
                required /></label
            ><label
              >{{ l('trialVersion')
              }}<select v-model="policy.configuration.trialVersionId" required>
                <option value="">—</option>
                <option
                  v-for="v in versions.filter((v) => v.accessClass === 'paid')"
                  :key="v.id"
                  :value="v.id"
                >
                  {{ versionLabel(v) }}
                </option>
              </select></label
            ><label
              >{{ l('fallbackVersion')
              }}<select v-model="policy.configuration.fallbackVersionId" required>
                <option value="">—</option>
                <option
                  v-for="v in versions.filter((v) => v.accessClass === 'free')"
                  :key="v.id"
                  :value="v.id"
                >
                  {{ versionLabel(v) }}
                </option>
              </select></label
            >
          </div>
          <h3>{{ l('eligibility') }}</h3>
          <p>{{ l('verified') }}</p>
          <h3>{{ l('retention') }}</h3>
          <p>{{ l('keepData') }}</p>
        </fieldset>
        <div class="plans-actions">
          <LoadingButton type="submit" v-if="!policyReadOnly" class="button primary" :busy="busy">{{
            l('save')
          }}</LoadingButton
          ><button
            v-if="policy.id && !policyReadOnly"
            type="button"
            class="button secondary"
            :disabled="busy || !!dirty"
            @click="requestPreview('policy')"
          >
            {{ l('preview') }}
          </button>
        </div>
      </form>
    </template>
    <template v-if="mode === 'rules'">
      <p>{{ l('ruleHint') }}</p>
      <button class="button primary" :disabled="busy" @click="selectRule()">
        {{ l('newRule') }}
      </button>
      <div class="plans-catalog">
        <article v-for="row in rules" :key="row.id" class="surface plans-card">
          <h2>
            {{ versionLabel(versions.find((v) => v.id === row.sourceVersionId)) }} →
            {{ versionLabel(versions.find((v) => v.id === row.targetVersionId)) }}
          </h2>
          <p>
            {{ l(row.timing) }} · {{ l(row.initiator) }} · {{ l('enabled') }}:
            {{ l(row.enabled ? 'yes' : 'no') }}
          </p>
          <button class="button secondary" :disabled="busy" @click="selectRule(row)">
            {{ l('edit') }}
          </button>
        </article>
      </div>
      <form v-if="rule" class="surface plans-editor" @submit.prevent="saveRule">
        <fieldset :disabled="busy">
          <legend>{{ l('rules') }}</legend>
          <div class="plans-feature-grid">
            <label
              >{{ l('source')
              }}<select v-model="rule.sourceVersionId" :disabled="!!rule.id" required>
                <option value="">—</option>
                <option v-for="v in versions" :key="v.id" :value="v.id">
                  {{ versionLabel(v) }}
                </option>
              </select></label
            ><label
              >{{ l('target')
              }}<select v-model="rule.targetVersionId" :disabled="!!rule.id" required>
                <option value="">—</option>
                <option v-for="v in versions" :key="v.id" :value="v.id">
                  {{ versionLabel(v) }}
                </option>
              </select></label
            ><label
              >{{ l('initiator')
              }}<select v-model="rule.initiator" :disabled="!!rule.id">
                <option value="platform">{{ l('platform') }}</option>
                <option value="owner">{{ l('owner') }}</option>
              </select></label
            ><label
              >{{ l('timing')
              }}<select v-model="rule.timing">
                <option value="immediate">{{ l('immediate') }}</option>
                <option value="period_end">{{ l('period_end') }}</option>
              </select></label
            ><label
              >{{ l('activeOverage')
              }}<select v-model="rule.overagePolicy.active">
                <option value="select_and_suspend">{{ l('select_and_suspend') }}</option>
                <option value="reject">{{ l('reject') }}</option>
              </select></label
            ><label
              >{{ l('storageOverage')
              }}<select v-model="rule.overagePolicy.storage">
                <option value="read_only">{{ l('read_only') }}</option>
                <option value="reject">{{ l('reject') }}</option>
              </select></label
            >
          </div>
          <p>{{ l('manual') }}</p>
          <label class="plans-check"
            ><input v-model="rule.enabled" type="checkbox" />{{ l('enabled') }}</label
          >
        </fieldset>
        <LoadingButton type="submit" class="button primary" :busy="busy">{{
          l('saveRule')
        }}</LoadingButton>
      </form>
      <form class="surface plans-editor" @submit.prevent="copyPreview">
        <h2>{{ l('copyRules') }}</h2>
        <div class="plans-feature-grid">
          <label
            >{{ l('from')
            }}<select v-model="copy.fromVersionId" required>
              <option value="">—</option>
              <option v-for="v in versions" :key="v.id" :value="v.id">{{ versionLabel(v) }}</option>
            </select></label
          ><label
            >{{ l('to')
            }}<select v-model="copy.toVersionId" required>
              <option value="">—</option>
              <option
                v-for="v in versions.filter(
                  (v) =>
                    v.planId === versions.find((v) => v.id === copy.fromVersionId)?.planId &&
                    v.id !== copy.fromVersionId,
                )"
                :key="v.id"
                :value="v.id"
              >
                {{ versionLabel(v) }}
              </option>
            </select></label
          >
        </div>
        <LoadingButton type="submit" class="button secondary" :busy="busy">{{
          l('copyPreview')
        }}</LoadingButton>
      </form>
    </template>
    <div v-if="['catalog', 'policies', 'rules'].includes(mode)" class="plans-actions">
      <button
        class="button secondary"
        :disabled="busy || meta.page <= 1"
        @click="confirmLeave() && load(meta.page - 1)"
      >
        {{ l('previous') }}</button
      ><span>{{ l('page') }} {{ meta.page }} / {{ meta.lastPage }}</span
      ><button
        class="button secondary"
        :disabled="busy || meta.page >= meta.lastPage"
        @click="confirmLeave() && load(meta.page + 1)"
      >
        {{ l('next') }}
      </button>
    </div>
    <button
      v-if="['policies', 'rules'].includes(mode) && versionsMeta.page < versionsMeta.lastPage"
      class="text-button"
      :disabled="busy"
      @click="run(publishedMore)"
    >
      {{ l('loadMore') }}
    </button>
    <BaseDialog
      :open="!!preview"
      :title="l(previewKind === 'copy' ? 'copyRules' : 'diff')"
      :dismiss-on-backdrop="!busy"
      @close="!busy && (preview = null)"
    >
      <template v-if="preview"
        ><template v-if="previewKind === 'copy'"
          ><p>{{ l('conflicts') }}: {{ preview.summary.conflicts.length }}</p>
          <ul>
            <li
              v-for="candidate in preview.summary.candidates"
              :key="candidate.source + candidate.target + candidate.rule.initiator"
            >
              {{ candidate.source }} → {{ candidate.target }} · {{ l('manual') }}
            </li>
          </ul>
          <LoadingButton
            class="button primary"
            :busy="busy"
            :disabled="!!preview.summary.conflicts.length"
            @click="confirmCopy"
            >{{ l('confirmCopy') }}</LoadingButton
          ></template
        ><template v-else
          ><p>{{ l('keep') }}</p>
          <p>{{ l('impact') }}</p>
          <p>{{ l('rollout') }}</p>
          <p v-if="!preview.summary.diff.length">{{ l('noChanges') }}</p>
          <dl class="plans-diff">
            <template v-for="change in preview.summary.diff" :key="change.field"
              ><dt>{{ change.field }}</dt>
              <dd>
                <span>{{ l('before') }}: {{ showValue(change.before) }}</span
                ><span>{{ l('after') }}: {{ showValue(change.after) }}</span>
              </dd></template
            >
          </dl>
          <LoadingButton class="button primary" :busy="busy" @click="publish">{{
            l('publish')
          }}</LoadingButton></template
        ></template
      >
    </BaseDialog>
  </section>
</template>
<style>
.plans-page {
  width: min(1200px, 100%);
  margin: 0 auto;
  padding: clamp(1rem, 3vw, 2rem);
  min-width: 0;
  box-sizing: border-box;
}
.plans-page .platform-tabs {
  display: flex;
  gap: 0.6rem;
  margin-block: 1rem;
  flex-wrap: wrap;
}
.plans-controls,
.plans-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: end;
  margin-block: 1rem;
}
.plans-controls {
  padding: 1rem;
}
.plans-page label:not(.plans-check) {
  display: grid;
  gap: 0.4rem;
  min-width: 0;
}
.plans-page input:not([type='checkbox']),
.plans-page select,
.plans-page textarea {
  width: 100%;
  min-width: 0;
  max-width: 100%;
}
.plans-page fieldset {
  border: 0;
  padding: 0;
  min-width: 0;
}
.plans-page fieldset:disabled {
  opacity: 1;
}
.plans-catalog,
.plans-feature-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 18rem), 1fr));
  gap: 1rem;
  margin-block: 1rem;
}
.plans-card,
.plans-editor,
.plans-feature {
  padding: 1rem;
  min-width: 0;
  overflow-wrap: anywhere;
}
.plans-card header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.plans-card h2,
.plans-feature h3 {
  margin-top: 0;
}
.plans-editor {
  margin-block: 1rem;
  display: grid;
  gap: 1rem;
}
.plans-editor section {
  margin-top: 1.5rem;
}
.plans-feature small {
  opacity: 0.8;
}
.plans-feature label {
  margin-top: 0.75rem;
}
.plans-check {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}
.plans-check input[type='checkbox'] {
  width: 1.125rem;
  height: 1.125rem;
  min-height: 0;
  padding: 0;
  flex: 0 0 1.125rem;
  accent-color: var(--action);
}
.plans-page select {
  min-height: 44px;
  padding: 10px var(--select-end) 10px 12px;
  border: 1px solid var(--input-border);
  border-radius: 6px;
  background-color: var(--input-bg);
  color: var(--text);
  line-height: var(--select-line-height);
}
.plans-page fieldset > label {
  margin-block: 0.8rem;
}
.plans-page input:disabled,
.plans-page textarea:disabled,
.plans-page select:disabled {
  opacity: 1;
  -webkit-text-fill-color: var(--text);
}

.plans-error {
  padding: 1rem;
  border: 1px solid var(--border);
}
.plans-diff {
  display: grid;
  gap: 0.5rem;
  overflow-wrap: anywhere;
  max-height: 50vh;
  overflow: auto;
}
.plans-diff dd {
  margin: 0 0 1rem;
  display: grid;
  gap: 0.4rem;
}
.plans-page .button {
  white-space: normal;
}
@media (max-width: 600px) {
  .plans-controls > * {
    width: 100%;
  }
  .plans-editor,
  .plans-card,
  .plans-feature {
    padding: 1rem;
  }
  .plans-actions {
    align-items: stretch;
  }
}
.plans-catalog {
  align-items: start;
}
.plans-system-check {
  display: flex;
  align-items: center;
  gap: 8px;
}
.plans-system-check input {
  width: 18px;
  height: 18px;
}
</style>

<style scoped>
.plans-editor {
  gap: 0.75rem;
  padding: 1rem;
}
.plans-editor section {
  margin-top: 1rem;
}
.plans-editor fieldset {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem 1rem;
}
.plans-editor fieldset > section {
  grid-column: 1 / -1;
}
.plans-page fieldset > label {
  margin-block: 0.25rem;
}
.plans-feature-grid {
  gap: 0.65rem;
  margin-block: 0.6rem;
}
.plans-feature {
  padding: 0.75rem;
}
.plans-feature h3 {
  margin-bottom: 0.25rem;
  font-size: 0.9rem;
}
.plans-feature small {
  font-size: 0.7rem;
  color: var(--text-subtle);
}
.plans-language-tabs,
.plans-translation-panel {
  grid-column: 1 / -1;
}
.plans-language-tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.plans-language-tabs [aria-pressed='true'] {
  border-color: var(--action);
  color: var(--action);
}
.plans-save-bar {
  position: sticky;
  bottom: 12px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px;
  z-index: 2;
  box-shadow: 0 4px 18px var(--popover-shadow);
}
.plans-save-bar > span {
  margin-right: auto;
  font-size: 0.8rem;
}
.plans-card {
  padding: 1rem;
}
.plans-card h2 {
  font-size: 1.1rem;
  margin-bottom: 0.4rem;
}
.plans-card p {
  margin: 0.4rem 0;
}
.plans-card code {
  color: var(--text-subtle);
  font-size: 0.8rem;
}
@media (max-width: 700px) {
  .plans-editor fieldset {
    grid-template-columns: 1fr;
  }
}
.plans-catalog {
  align-items: start;
}
.plans-system-check {
  display: flex;
  align-items: center;
  gap: 8px;
}
.plans-system-check input {
  width: 18px;
  height: 18px;
}
</style>

<style scoped>
.plans-key-limits {
  display: flex;
  gap: 12px 20px;
  flex-wrap: wrap;
  margin: 12px 0;
}
.plans-key-limits div {
  display: grid;
  gap: 3px;
}
.plans-key-limits dt {
  font-size: 11px;
  color: var(--text-subtle);
}
.plans-key-limits dd {
  margin: 0;
  font-weight: 650;
  font-size: 13px;
}
.plans-more-actions {
  margin-left: auto;
  font-size: 12px;
}
.plans-more-actions summary {
  cursor: pointer;
  padding: 8px;
}
.plans-more-actions button {
  margin: 4px;
}
.plans-catalog {
  align-items: start;
}
.plans-system-check {
  display: flex;
  align-items: center;
  gap: 8px;
}
.plans-system-check input {
  width: 18px;
  height: 18px;
}
</style>
