<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { api } from '../api/client.js'
import { t, formatDate, formatApiError } from '../i18n/index.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import BaseDialog from './BaseDialog.vue'
import LoadingButton from './LoadingButton.vue'
import AppIcon from './AppIcon.vue'

const nav = useAdminNavigationStore()
const workspaceId = nav.workspace?.id
const projects = ref([]),
  expanded = ref(false),
  loading = ref(false),
  busy = ref(false)
const error = ref(null),
  action = ref(null),
  confirmed = ref(false)
const isOwner = computed(() => nav.user?.membershipRole === 'owner')
let active = true,
  revision = 0
onUnmounted(() => {
  active = false
})
const current = () => active && nav.workspace?.id === workspaceId
async function refresh() {
  const requestRevision = ++revision
  loading.value = true
  error.value = null
  try {
    const result = await api.request('/api/admin/projects/trash')
    if (current() && requestRevision === revision) projects.value = result.data
  } catch (cause) {
    if (current() && requestRevision === revision) error.value = cause
  } finally {
    if (requestRevision === revision) loading.value = false
  }
}
function ask(project = null) {
  confirmed.value = false
  error.value = null
  action.value = { project, ids: projects.value.map((item) => item.id) }
}
async function restore(project) {
  if (busy.value) return
  busy.value = true
  error.value = null
  try {
    await api.request(`/api/admin/projects/${project.id}/restore`, { method: 'POST' })
    if (!current()) return
    await Promise.all([refresh(), nav.refreshProjects()])
  } catch (cause) {
    if (current()) error.value = cause
  } finally {
    busy.value = false
  }
}
async function remove() {
  if (!confirmed.value || busy.value || !action.value) return
  busy.value = true
  error.value = null
  const pending = action.value
  try {
    await api.request(
      pending.project
        ? `/api/admin/projects/${pending.project.id}/permanent`
        : '/api/admin/projects/trash',
      { method: 'DELETE', body: { confirmed: true, projectIds: pending.ids } },
    )
    if (!current()) return
    action.value = null
    await refresh()
  } catch (cause) {
    if (current()) {
      error.value = cause
      if (cause.status === 409) {
        confirmed.value = false
        await refresh()
        action.value = null
        error.value = cause
      }
    }
  } finally {
    busy.value = false
  }
}
onMounted(refresh)
defineExpose({ refresh })
</script>
<template>
  <section class="project-recycle-bin surface" :aria-label="t('projectList.trash')">
    <div class="project-bin-heading">
      <button
        class="text-button project-bin-toggle"
        :aria-expanded="expanded"
        aria-controls="project-bin-content"
        @click="expanded = !expanded"
      >
        <AppIcon name="trash-2" :size="18" />
        {{ t('projectList.trashCount', { count: projects.length }) }}
        <span aria-hidden="true">{{ expanded ? '−' : '+' }}</span>
      </button>
      <button
        v-if="expanded && isOwner && projects.length"
        class="text-button project-danger"
        :disabled="busy || loading"
        @click="ask()"
      >
        {{ t('projectList.emptyTrash') }}
      </button>
    </div>
    <p v-if="error && !action" role="alert" class="field-error">
      {{ error.status === 409 ? t('projectList.trashChanged') : formatApiError(error) }}
    </p>
    <div v-if="expanded" id="project-bin-content">
      <p v-if="loading" role="status" class="small muted">{{ t('nav.loading') }}</p>
      <button v-if="error" class="text-button" @click="refresh">{{ t('ui.tryAgain') }}</button>
      <p v-if="!loading && !projects.length" class="muted">{{ t('projectList.empty') }}</p>
      <ul v-else class="project-bin-list">
        <li v-for="project in projects" :key="project.id">
          <div>
            <strong>{{ project.name }}</strong>
            <p class="small muted">
              {{ t('projectList.trashedOn', { date: formatDate(project.deletedAt) }) }}
            </p>
          </div>
          <div class="admin-project-actions">
            <LoadingButton class="button secondary" :busy="busy" @click="restore(project)">{{
              t('management.restore')
            }}</LoadingButton>
            <button
              v-if="isOwner"
              class="text-button project-danger"
              :disabled="busy"
              @click="ask(project)"
            >
              {{ t('projectList.deleteForever') }}
            </button>
          </div>
        </li>
      </ul>
    </div>
  </section>
  <BaseDialog
    :open="!!action"
    :title="t(action?.project ? 'projectList.deleteForever' : 'projectList.emptyTrash')"
    id="project-purge-title"
    @close="!busy && (action = null)"
  >
    <p>
      {{
        action?.project
          ? t('projectList.purgeWarning', { name: action.project.name })
          : t('projectList.emptyWarning', { count: action?.ids.length || 0 })
      }}
    </p>
    <label class="project-purge-confirm"
      ><input v-model="confirmed" type="checkbox" :disabled="busy" />{{
        t('projectList.confirmPermanent')
      }}</label
    >
    <p v-if="error" role="alert" class="field-error">{{ formatApiError(error) }}</p>
    <template #footer>
      <button class="button secondary" :disabled="busy" @click="action = null">
        {{ t('ui.cancel') }}
      </button>
      <LoadingButton class="button danger" :busy="busy" :disabled="!confirmed" @click="remove">{{
        t(action?.project ? 'projectList.deleteForever' : 'projectList.emptyTrash')
      }}</LoadingButton>
    </template>
  </BaseDialog>
</template>
