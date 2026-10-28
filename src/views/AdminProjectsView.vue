<script setup>
import { ref, watch, computed, onBeforeUnmount, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useProjectListingStore, listQuery } from '../stores/projectListing.js'
import { canCreateProjects, hasProjectPermission } from '../services/projectPermissions.js'
import UnreadBadge from '../components/UnreadBadge.vue'
import AppIcon from '../components/AppIcon.vue'
import BaseDialog from '../components/BaseDialog.vue'
import LoadingButton from '../components/LoadingButton.vue'
import LoadingOverlay from '../components/LoadingOverlay.vue'
import ProjectRecycleBin from '../components/ProjectRecycleBin.vue'
import FeedbackRequestButton from '../components/FeedbackRequestButton.vue'
import { api } from '../api/client.js'
import { t, formatApiError, formatDate } from '../i18n/index.js'

import { useAdminNavigationStore } from '../stores/adminNavigation.js'
const nav = useAdminNavigationStore()
const listing = useProjectListingStore(),
  route = useRoute(),
  router = useRouter()
const search = ref(''),
  filtersOpen = ref(false),
  filterTrigger = ref(null)
let timer
const activeFilters = computed(
  () =>
    !!(String(route.query.q || '').trim().length >= 3 || route.query.status || route.query.sort),
)
const pages = computed(() =>
  Array.from(
    { length: Math.min(5, listing.meta.last_page) },
    (_, i) => Math.max(1, Math.min(listing.meta.current_page - 2, listing.meta.last_page - 4)) + i,
  ),
)
function filters(patch = {}) {
  clearTimeout(timer)
  router.replace({
    path: '/admin',
    query: listQuery({ ...route.query, q: search.value, ...patch, page: 1 }),
  })
}
function resetFilters() {
  search.value = ''
  filters({ q: '', status: '', sort: '' })
}
function debounce() {
  clearTimeout(timer)
  listing.cancel()
  timer = setTimeout(() => filters(), 480)
}
function page(number) {
  router.push({ path: '/admin', query: listQuery({ ...route.query, page: number }) })
}
async function closeFilters() {
  filtersOpen.value = false
  await nextTick()
  filterTrigger.value?.focus()
}
async function reload(force = false) {
  if (force) listing.refresh()
  if (!nav.workspace) return
  const id = nav.workspace.id
  const ok = await listing.load(id, route.query)
  if (ok && nav.workspace?.id === id && route.path === '/admin') {
    const actual = listing.meta.current_page
    if (actual !== (Number(route.query.page) || 1))
      await router.replace({ path: '/admin', query: listQuery({ ...route.query, page: actual }) })
  }
}
watch(
  () => [nav.workspace?.id, route.fullPath],
  () => {
    if (route.path !== '/admin') return
    search.value = String(route.query.q || '')
    nav.projectsPath = route.fullPath
    reload()
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  clearTimeout(timer)
  listing.clear()
})
const bin = ref(null),
  candidate = ref(null),
  busy = ref(false),
  error = ref(null)
const progress = (project) =>
  project.tasksCount ? Math.round((100 * project.doneCount) / project.tasksCount) : 0
watch(
  () => nav.workspace?.id,
  () => {
    candidate.value = null
    error.value = null
  },
)
function askTrash(project) {
  candidate.value = project
  error.value = null
}
async function trash() {
  if (busy.value || !candidate.value) return
  const project = candidate.value,
    workspaceId = nav.workspace?.id
  busy.value = true
  try {
    await api.request(`/api/admin/projects/${project.id}/trash`, { method: 'POST' })
    if (nav.workspace?.id !== workspaceId) return
    nav.removeProject(project.id)
    candidate.value = null
    await Promise.all([bin.value?.refresh(), reload(true)])
  } catch (cause) {
    if (nav.workspace?.id === workspaceId) error.value = cause
  } finally {
    busy.value = false
  }
}
</script>
<template>
  <div>
    <div class="admin-page-heading">
      <div>
        <h1>{{ t('ui.projects') }}</h1>
        <p class="muted">{{ t('ui.ideasThatBecomeFinishedWebsites') }}</p>
      </div>
      <RouterLink
        v-if="canCreateProjects(nav.user)"
        to="/admin/projects/new"
        class="button primary"
        >{{ t('ui.newProject') }}</RouterLink
      >
    </div>
    <div class="project-list-controls">
      <div class="project-search-row">
        <label class="project-search"
          ><AppIcon name="search" :size="18" /><span class="sr-only">{{
            t('branding.search')
          }}</span
          ><input
            v-model="search"
            type="search"
            maxlength="150"
            :placeholder="t('branding.search')"
            @input="debounce" /></label
        ><button
          type="button"
          class="button secondary project-filter-trigger"
          ref="filterTrigger"
          :class="{ 'has-active': activeFilters }"
          :aria-label="t('branding.filters')"
          @keydown.esc.stop.prevent="closeFilters"
          :aria-expanded="filtersOpen"
          aria-controls="project-list-filters"
          @click="filtersOpen = !filtersOpen"
        >
          <AppIcon name="settings" :size="20" />
        </button>
      </div>
      <div
        id="project-list-filters"
        class="project-filters-extra"
        :class="{ 'is-open': filtersOpen }"
        @keydown.esc.stop.prevent="closeFilters"
      >
        <select
          :value="route.query.status || ''"
          :aria-label="t('branding.allStatuses')"
          @change="filters({ status: $event.target.value })"
        >
          <option value="">{{ t('branding.allStatuses') }}</option>
          <option value="active">{{ t('ui.active') }}</option>
          <option value="inactive">{{ t('ui.inactive') }}</option>
        </select>
        <select
          :value="route.query.sort || ''"
          :aria-label="t('branding.filters')"
          @change="filters({ sort: $event.target.value })"
        >
          <option value="">{{ t('branding.updated') }}</option>
          <option value="created">{{ t('branding.created') }}</option>
          <option value="name">{{ t('branding.nameSort') }}</option>
        </select>
      </div>
      <div class="project-list-results">
        <span role="status">{{ t('branding.results', { total: listing.meta.total }) }}</span
        ><button v-if="activeFilters" type="button" class="text-button" @click="resetFilters">
          {{ t('branding.clear') }}
        </button>
      </div>
    </div>
    <LoadingOverlay :active="listing.loading" class="navigation-loading project-query-loading" />
    <div v-if="listing.error" role="alert">
      <p class="field-error">{{ formatApiError(listing.error) }}</p>
      <button class="button secondary" @click="reload(true)">
        {{ t('ui.tryAgain') }}
      </button>
    </div>
    <div v-else-if="!listing.projects.length && !listing.loading" class="empty-state">
      <h2>
        {{
          t(
            activeFilters
              ? 'branding.noResults'
              : nav.user?.membershipRole === 'owner'
                ? 'ui.startWithYourFirstProject'
                : 'team.noProjects',
          )
        }}
      </h2>
      <p>
        {{
          t(
            nav.user?.membershipRole === 'owner'
              ? 'ui.createAProjectAndSendYourClientA'
              : 'team.noProjectsHint',
          )
        }}
      </p>
    </div>
    <div v-else class="admin-project-list">
      <article
        v-for="project in listing.projects"
        :key="project.id"
        class="surface admin-project-card"
      >
        <div class="project-card-information">
          <div class="project-card-title">
            <span
              class="project-activity"
              :class="{ 'is-active': project.status === 'active' }"
              :title="t(project.status === 'active' ? 'ui.active' : 'ui.inactive')"
              role="img"
              :aria-label="t(project.status === 'active' ? 'ui.active' : 'ui.inactive')"
              ><AppIcon v-if="project.status === 'active'" name="check" :size="12"
            /></span>
            <h2>
              <RouterLink :to="`/admin/projects/${project.id}/brief`">{{
                project.name
              }}</RouterLink>
              <UnreadBadge :count="project.unreadCount || 0" />
            </h2>
          </div>
          <p class="muted project-card-website">
            {{ project.website || t('ui.noWebsiteAddress') }}
          </p>
          <p v-if="project.updatedAt" class="small muted">
            {{ t('projectList.updated', { date: formatDate(project.updatedAt) }) }}
          </p>
          <dl class="project-card-metadata">
            <div>
              <dt><AppIcon name="users" :size="16" />{{ t('ui.clients') }}</dt>
              <dd>{{ project.clientsCount ?? 0 }}</dd>
            </div>
            <div>
              <dt><AppIcon name="user-check" :size="16" />{{ t('projectList.team') }}</dt>
              <dd>{{ project.teamCount ?? 0 }}</dd>
            </div>
            <div>
              <dt>{{ t('projectList.currency') }}</dt>
              <dd>{{ project.currency }}</dd>
            </div>
          </dl>
          <div class="project-card-progress">
            <div>
              <span>{{
                t('flow.progress', { done: project.doneCount, total: project.tasksCount })
              }}</span
              ><strong>{{ progress(project) }}%</strong>
            </div>
            <progress
              :value="progress(project)"
              max="100"
              :aria-label="t('projectList.progress')"
            />
          </div>
        </div>
        <div class="admin-project-actions">
          <FeedbackRequestButton :project="project" />
          <RouterLink :to="`/admin/projects/${project.id}/brief`" class="button primary">{{
            t('ui.open')
          }}</RouterLink
          ><RouterLink
            v-if="hasProjectPermission(project, 'manage_clients')"
            :to="`/admin/projects/${project.id}/clients`"
            class="button secondary"
            >{{ t('ui.clients') }}</RouterLink
          ><RouterLink
            v-if="nav.user?.membershipRole === 'owner'"
            :to="`/admin/projects/${project.id}/team`"
            class="button secondary"
            >{{ t('projectList.team') }}</RouterLink
          ><RouterLink
            v-if="hasProjectPermission(project, 'manage_project_settings')"
            :to="`/admin/projects/${project.id}/settings`"
            class="text-button"
            >{{ t('ui.settings') }}</RouterLink
          >
          <button
            v-if="hasProjectPermission(project, 'manage_trash')"
            class="icon-button project-danger"
            :title="t('projectList.moveToTrash')"
            :aria-label="t('projectList.moveToTrash')"
            :disabled="busy"
            @click="askTrash(project)"
          >
            <AppIcon name="trash-2" />
          </button>
        </div>
      </article>
    </div>
    <nav
      v-if="listing.meta.last_page > 1"
      class="project-pagination"
      :aria-label="
        t('branding.page', { page: listing.meta.current_page, pages: listing.meta.last_page })
      "
    >
      <button
        type="button"
        class="button secondary"
        :disabled="listing.loading || listing.meta.current_page <= 1"
        @click="page(listing.meta.current_page - 1)"
      >
        {{ t('branding.previous') }}</button
      ><button
        v-for="number in pages"
        :key="number"
        type="button"
        class="button secondary page-number"
        :aria-current="number === listing.meta.current_page ? 'page' : undefined"
        :disabled="listing.loading"
        @click="page(number)"
      >
        {{ number }}</button
      ><span class="pagination-summary">{{
        t('branding.page', { page: listing.meta.current_page, pages: listing.meta.last_page })
      }}</span
      ><button
        type="button"
        class="button secondary"
        :disabled="listing.loading || listing.meta.current_page >= listing.meta.last_page"
        @click="page(listing.meta.current_page + 1)"
      >
        {{ t('branding.next') }}
      </button>
    </nav>
    <ProjectRecycleBin
      v-if="nav.workspace"
      :key="nav.workspace.id"
      ref="bin"
      @changed="reload(true)"
    />
    <BaseDialog
      :open="!!candidate"
      :title="t('projectList.moveToTrash')"
      id="project-trash-confirm-title"
      @close="!busy && (candidate = null)"
    >
      <p>{{ t('projectList.trashWarning', { name: candidate?.name || '' }) }}</p>
      <p v-if="error" role="alert" class="field-error">{{ formatApiError(error) }}</p>
      <template #footer>
        <button class="button secondary" :disabled="busy" @click="candidate = null">
          {{ t('ui.cancel') }}
        </button>
        <LoadingButton class="button danger" :busy="busy" @click="trash">{{
          t('projectList.moveToTrash')
        }}</LoadingButton>
      </template>
    </BaseDialog>
  </div>
</template>
