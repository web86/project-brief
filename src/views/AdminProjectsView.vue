<script setup>
import { onMounted } from 'vue'
import { canCreateProjects, hasProjectPermission } from '../services/projectPermissions.js'
import UnreadBadge from '../components/UnreadBadge.vue'
import { t, formatApiError } from '../i18n/index.js'

import { useAdminNavigationStore } from '../stores/adminNavigation.js'
const nav = useAdminNavigationStore()
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
        <div>
          <h2>{{ project.name }} <UnreadBadge :count="project.unreadCount || 0" /></h2>
          <p class="muted">{{ project.website || t('ui.noWebsiteAddress') }}</p>
          <p class="small muted">
            {{ t('flow.progress', { done: project.doneCount, total: project.tasksCount }) }} ·
            {{ project.status === 'active' ? t('ui.active') : t('ui.inactive') }}
          </p>
        </div>
        <div class="admin-project-actions">
          <RouterLink :to="`/admin/projects/${project.id}/brief`" class="button primary">{{
            t('ui.open')
          }}</RouterLink
          ><RouterLink
            v-if="hasProjectPermission(project, 'manage_clients')"
            :to="`/admin/projects/${project.id}/clients`"
            class="button secondary"
            >{{ t('ui.clients') }}</RouterLink
          ><RouterLink
            v-if="hasProjectPermission(project, 'manage_project_settings')"
            :to="`/admin/projects/${project.id}/settings`"
            class="text-button"
            >{{ t('ui.settings') }}</RouterLink
          >
        </div>
      </article>
    </div>
  </div>
</template>
