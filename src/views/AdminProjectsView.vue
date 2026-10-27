<script setup>
import { onMounted, ref, watch } from 'vue'
import { canCreateProjects, hasProjectPermission } from '../services/projectPermissions.js'
import UnreadBadge from '../components/UnreadBadge.vue'
import AppIcon from '../components/AppIcon.vue'
import BaseDialog from '../components/BaseDialog.vue'
import LoadingButton from '../components/LoadingButton.vue'
import ProjectRecycleBin from '../components/ProjectRecycleBin.vue'
import FeedbackRequestButton from '../components/FeedbackRequestButton.vue'
import { api } from '../api/client.js'
import { t, formatApiError, formatDate } from '../i18n/index.js'

import { useAdminNavigationStore } from '../stores/adminNavigation.js'
const nav = useAdminNavigationStore()
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
    await bin.value?.refresh()
  } catch (cause) {
    if (nav.workspace?.id === workspaceId) error.value = cause
  } finally {
    busy.value = false
  }
}
onMounted(() => nav.refreshProjects())
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
    <p v-if="nav.refreshing" role="status" class="small muted">{{ t('nav.loading') }}</p>
    <div v-if="nav.listError" role="alert">
      <p class="field-error">{{ formatApiError(nav.listError) }}</p>
      <button class="button secondary" @click="nav.refreshProjects()">
        {{ t('ui.tryAgain') }}
      </button>
    </div>
    <div v-else-if="!nav.projects.length" class="empty-state">
      <h2>
        {{
          t(
            nav.user?.membershipRole === 'owner'
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
      <article v-for="project in nav.projects" :key="project.id" class="surface admin-project-card">
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
    <ProjectRecycleBin v-if="nav.workspace" :key="nav.workspace.id" ref="bin" />
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
