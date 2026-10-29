<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { useProjectRequestsStore } from '../stores/projectRequests.js'
import { t, intlLocale, formatApiError } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import BaseDialog from '../components/BaseDialog.vue'
import LoadingButton from '../components/LoadingButton.vue'
import AppIcon from '../components/AppIcon.vue'
const nav = useAdminNavigationStore(),
  requests = useProjectRequestsStore(),
  route = useRoute(),
  router = useRouter()
const sendInvitation = ref(true),
  rejecting = ref(false),
  reason = ref('')
const allowed = computed(() => nav.user?.membershipRole === 'owner' && !!nav.workspace)
const selected = computed(() => requests.selected)
function date(value) {
  return value
    ? new Intl.DateTimeFormat(intlLocale(), { dateStyle: 'medium', timeStyle: 'short' }).format(
        new Date(value),
      )
    : '—'
}
async function load(reset = false) {
  if (!allowed.value) return
  if (reset) requests.page = 1
  const result = await requests.load(nav.workspace.id)
  if (result) nav.pendingRequests = result.meta.pending
}
function selectStatus(value) {
  requests.status = value
  void load(true)
}
function changePage(amount) {
  requests.page += amount
  void load()
}
async function open(id) {
  await router.replace({ query: { ...route.query, request: id } })
}
async function close() {
  if (requests.busy) return
  requests.close()
  rejecting.value = false
  const query = { ...route.query }
  delete query.request
  await router.replace({ query })
}
async function decide(action) {
  const saved = await requests.decide(
    action,
    action === 'approve'
      ? { sendInvitation: sendInvitation.value }
      : { reason: reason.value.trim() || null },
  )
  if (saved) {
    rejecting.value = false
    nav.pendingRequests = requests.meta?.pending ?? nav.pendingRequests
    toast.success(() => t('requests.' + (action === 'approve' ? 'approvedToast' : 'rejectedToast')))
  }
}
watch(
  () => [nav.workspace?.id, nav.user?.membershipRole],
  async () => {
    requests.clear()
    if (allowed.value) {
      await load()
      if (route.query.request) await requests.open(route.query.request)
    }
  },
  { immediate: true },
)
watch(
  () => route.query.request,
  (id) => {
    if (allowed.value && id) requests.open(id)
    else requests.close()
  },
)
watch(
  () => selected.value?.id,
  () => {
    sendInvitation.value = true
    rejecting.value = false
    reason.value = ''
  },
)
onBeforeUnmount(() => requests.clear())
</script>
<template>
  <div class="project-requests-page" v-if="allowed">
    <div class="page-heading">
      <div>
        <h1>{{ t('requests.title') }}</h1>
        <p class="muted">{{ t('requests.hint') }}</p>
      </div>
      <button
        class="button secondary"
        :disabled="requests.loading || requests.busy"
        @click="load()"
      >
        <AppIcon name="refresh" :size="17" />{{ t('provisioning.refresh') }}
      </button>
    </div>
    <div class="request-status-filters" :aria-label="t('requests.status')">
      <button
        v-for="value in ['pending', 'approved', 'rejected']"
        :key="value"
        class="button"
        :class="requests.status === value ? 'primary' : 'secondary'"
        :aria-pressed="requests.status === value"
        :disabled="requests.busy"
        @click="selectStatus(value)"
      >
        {{ t('requests.status_' + value)
        }}<span v-if="value === 'pending'" class="request-count">{{ nav.pendingRequests }}</span>
      </button>
    </div>
    <form class="request-search" @submit.prevent="load(true)">
      <label class="field" for="requests-search"
        >{{ t('requests.search')
        }}<input
          id="requests-search"
          v-model="requests.search"
          maxlength="255"
          :disabled="requests.busy"
      /></label>
      <label class="field" for="requests-sort"
        >{{ t('requests.sort')
        }}<select
          id="requests-sort"
          v-model="requests.sort"
          :disabled="requests.busy"
          @change="load(true)"
        >
          <option value="desc">{{ t('requests.newest') }}</option>
          <option value="asc">{{ t('requests.oldest') }}</option>
        </select></label
      >
      <button class="button secondary" :disabled="requests.loading || requests.busy">
        {{ t('requests.find') }}
      </button>
    </form>
    <p v-if="requests.error && !route.query.request" class="field-error" role="alert">
      {{ formatApiError(requests.error) }}
    </p>
    <p v-if="requests.loading" role="status">{{ t('nav.loading') }}</p>
    <p v-else-if="!requests.items.length && !requests.error" class="surface request-empty">
      {{ t('requests.empty') }}
    </p>
    <div class="request-list" :aria-busy="requests.loading">
      <article v-for="item in requests.items" :key="item.id" class="surface request-card">
        <div>
          <h2>{{ item.project.name }}</h2>
          <p>
            {{ item.client.name
            }}<span v-if="item.client.company"> · {{ item.client.company }}</span>
          </p>
          <p class="small muted request-email">{{ item.client.email }}</p>
        </div>
        <div class="request-card-meta">
          <span class="badge">{{ t('requests.status_' + item.status) }}</span
          ><span class="small muted">{{ item.source.name }} · {{ date(item.createdAt) }}</span>
        </div>
        <button class="button secondary" :disabled="requests.busy" @click="open(item.id)">
          {{ t('requests.view') }}
        </button>
      </article>
    </div>
    <div v-if="requests.meta && requests.meta.lastPage > 1" class="request-pagination">
      <button
        class="button secondary"
        :disabled="requests.loading || requests.page <= 1"
        @click="changePage(-1)"
      >
        {{ t('integrations.previous') }}
      </button>
      <span>{{ requests.page }} / {{ requests.meta.lastPage }}</span>
      <button
        class="button secondary"
        :disabled="requests.loading || requests.page >= requests.meta.lastPage"
        @click="changePage(1)"
      >
        {{ t('integrations.next') }}
      </button>
    </div>
    <BaseDialog
      :open="!!route.query.request"
      :title="t('requests.detail')"
      id="request-detail-title"
      @close="close"
    >
      <p v-if="requests.detailLoading" role="status">{{ t('nav.loading') }}</p>
      <p v-if="requests.error" class="field-error" role="alert">
        {{ formatApiError(requests.error) }}
      </p>
      <template v-if="selected">
        <h3 class="request-detail-name">{{ selected.project.name }}</h3>
        <p class="badge">{{ t('requests.status_' + selected.status) }}</p>
        <dl class="request-details">
          <div>
            <dt>{{ t('requests.client') }}</dt>
            <dd>{{ selected.client.name }}</dd>
          </div>
          <div>
            <dt>{{ t('common.email') }}</dt>
            <dd>{{ selected.client.email }}</dd>
          </div>
          <div v-if="selected.client.company">
            <dt>{{ t('requests.company') }}</dt>
            <dd>{{ selected.client.company }}</dd>
          </div>
          <div v-if="selected.client.website">
            <dt>{{ t('requests.website') }}</dt>
            <dd>
              <a :href="selected.client.website" target="_blank" rel="noopener noreferrer">{{
                selected.client.website
              }}</a>
            </dd>
          </div>
          <div>
            <dt>{{ t('requests.language') }}</dt>
            <dd>{{ selected.project.language }}</dd>
          </div>
          <div>
            <dt>{{ t('requests.source') }}</dt>
            <dd>{{ selected.source.name }}</dd>
          </div>
          <div>
            <dt>{{ t('requests.externalId') }}</dt>
            <dd>{{ selected.externalId }}</dd>
          </div>
          <div>
            <dt>{{ t('requests.created') }}</dt>
            <dd>{{ date(selected.createdAt) }}</dd>
          </div>
          <div>
            <dt>{{ t('requests.updated') }}</dt>
            <dd>{{ date(selected.updatedAt) }}</dd>
          </div>
        </dl>
        <h3>{{ t('requests.description') }}</h3>
        <p class="request-description">{{ selected.project.description || '—' }}</p>
        <template v-if="selected.decision">
          <h3>{{ t('requests.decision') }}</h3>
          <p>{{ date(selected.reviewedAt) }} · {{ selected.decision.actor.name }}</p>
          <p v-if="selected.decision.reason" class="request-description">
            {{ selected.decision.reason }}
          </p>
          <p v-if="selected.invitation">
            {{ t('provisioning.invitation') }}:
            {{ t('provisioning.status_' + selected.invitation) }}
          </p>
        </template>
        <template v-if="selected.status === 'pending'">
          <p class="muted small">{{ t('requests.identityHint') }}</p>
          <label v-if="!rejecting" class="request-invitation"
            ><input v-model="sendInvitation" type="checkbox" :disabled="requests.busy" />{{
              t('requests.sendInvitation')
            }}</label
          >
          <div v-else class="request-rejection">
            <p>{{ t('requests.rejectConfirm') }}</p>
            <label class="field" for="request-reason"
              >{{ t('requests.reason')
              }}<textarea
                id="request-reason"
                v-model="reason"
                maxlength="2000"
                :disabled="requests.busy"
              />
            </label>
          </div>
        </template>
      </template>
      <template #footer>
        <template v-if="selected?.status === 'pending'">
          <template v-if="rejecting"
            ><button class="button secondary" :disabled="requests.busy" @click="rejecting = false">
              {{ t('integrations.cancel') }}</button
            ><LoadingButton
              class="button danger"
              :busy="requests.busy"
              :disabled="requests.busy"
              @click="decide('reject')"
              >{{ t('requests.confirmReject') }}</LoadingButton
            ></template
          >
          <template v-else
            ><button class="button secondary" :disabled="requests.busy" @click="rejecting = true">
              {{ t('requests.reject') }}</button
            ><LoadingButton
              class="button primary"
              :busy="requests.busy"
              :disabled="requests.busy"
              @click="decide('approve')"
              >{{ t('requests.approve') }}</LoadingButton
            ></template
          >
        </template>
        <RouterLink
          v-else-if="selected?.projectId"
          :to="'/admin/projects/' + selected.projectId + '/brief'"
          class="button primary"
          >{{ t('requests.openProject') }}</RouterLink
        >
        <button class="button secondary" :disabled="requests.busy" @click="close">
          {{ t('ui.closeDialog') }}
        </button>
      </template>
    </BaseDialog>
  </div>
</template>
<style scoped>
.project-requests-page {
  display: grid;
  gap: 20px;
}
.project-requests-page > .page-heading {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 0;
}
.request-search .field {
  margin-bottom: 0;
}
:deep(.modal-footer) {
  flex-wrap: wrap;
}
.request-status-filters,
.request-pagination {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.request-search {
  display: flex;
  align-items: end;
  gap: 12px;
  flex-wrap: wrap;
}
.request-search .field:first-child {
  flex: 1;
  min-width: 180px;
}
.request-list {
  display: grid;
  gap: 12px;
}
.request-card {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(150px, auto) auto;
  align-items: center;
  padding: 20px;
  gap: 18px;
}
.request-card h2 {
  font-size: 18px;
  margin-bottom: 6px;
}
.request-card-meta {
  display: grid;
  gap: 8px;
  justify-items: start;
}
.request-count {
  min-width: 20px;
  padding: 0 5px;
  border-radius: 10px;
  background: var(--surface);
  color: var(--text);
}
.request-details {
  display: grid;
  gap: 12px;
  margin: 20px 0;
}
.request-details > div {
  display: grid;
  grid-template-columns: 130px minmax(0, 1fr);
  gap: 14px;
}
.request-details dt {
  color: var(--muted);
}
.request-details dd {
  margin: 0;
}
.request-description {
  white-space: pre-wrap;
  margin: 12px 0 20px;
}
.request-details,
.request-description,
.request-card,
.request-detail-name {
  overflow-wrap: anywhere;
}
.request-invitation {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 16px;
}
.request-invitation input {
  width: auto;
}
.request-rejection {
  display: grid;
  gap: 12px;
  margin-top: 16px;
}
.request-empty {
  padding: 24px;
}
@media (max-width: 680px) {
  .request-card {
    grid-template-columns: minmax(0, 1fr);
    gap: 12px;
    padding: 16px;
  }
  .request-card .button {
    justify-self: start;
  }
  .request-details > div {
    grid-template-columns: minmax(0, 1fr);
    gap: 2px;
  }
}
</style>
