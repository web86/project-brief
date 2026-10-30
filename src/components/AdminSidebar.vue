<script setup>
import BrandLogo from './PortalBrandLogo.vue'
import PersonalSettingsNav from './PersonalSettingsNav.vue'
import { canCreateProjects } from '../services/projectPermissions.js'
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
import ProjectSidebar from './ProjectSidebar.vue'
import AdminAccount from './AdminAccount.vue'
defineProps({ busy: Boolean, trashBusy: Boolean, trashOpen: Boolean })
const emit = defineEmits(['navigate', 'logout', 'trash'])
const nav = useAdminNavigationStore(),
  router = useRouter(),
  route = useRoute()
const project = computed(() => nav.projectForRoute(route.params.uuid))
async function select(event) {
  const id = event.target.value
  await nav.switchWorkspace(id, router)
  event.target.value = nav.workspace?.id || ''
  emit('navigate')
}
</script>
<template>
  <ProjectSidebar
    v-if="project"
    :project="project"
    :busy="busy"
    :trash-busy="trashBusy"
    :trash-open="trashOpen"
    @navigate="emit('navigate')"
    @logout="emit('logout')"
    @trash="emit('trash')"
  />
  <div v-else-if="!route.params.uuid" class="admin-sidebar-content">
    <RouterLink
      to="/admin"
      class="brand"
      :aria-label="t('ui.projectBriefOpenTheProject')"
      @click="emit('navigate')"
    >
      <BrandLogo />
    </RouterLink>
    <label class="sidebar-workspace">
      <span class="sidebar-workspace-label">
        {{ t('nav.workspace') }}
        <span class="workspace-help" tabindex="0" role="img" :aria-label="t('nav.workspaceHint')">
          <AppIcon name="info" :size="14" />
          <span class="workspace-tooltip" aria-hidden="true">{{ t('nav.workspaceHint') }}</span>
        </span>
      </span>
      <select
        :value="nav.workspace?.id || ''"
        :disabled="nav.loading || nav.switching || !nav.ready"
        @change="select"
      >
        <option v-if="!nav.workspace" value="">
          {{ t(nav.loading ? 'nav.loading' : 'centralNotifications.noWorkspace') }}
        </option>
        <option v-for="workspace in nav.workspaces" :key="workspace.id" :value="workspace.id">
          {{ workspace.name }}
        </option>
      </select>
    </label>
    <RouterLink
      to="/admin/projects/new"
      v-if="nav.workspace && canCreateProjects(nav.user)"
      class="button primary sidebar-create"
      @click="emit('navigate')"
    >
      <AppIcon name="plus" :size="17" />{{ t('nav.newProject') }}
    </RouterLink>
    <p v-if="!nav.workspace && nav.ready" class="small muted">
      {{ t('centralNotifications.chooseWorkspace') }}
    </p>
    <nav v-if="nav.workspace" class="global-project-navigation" :aria-label="t('nav.projects')">
      <RouterLink
        :to="nav.projectsPath"
        class="sidebar-all-projects"
        :aria-current="route.path === '/admin' ? 'page' : undefined"
        @click="emit('navigate')"
      >
        <AppIcon :name="route.path === '/admin' ? 'grid' : 'arrow'" :size="20" /><span>{{
          t('nav.allProjects')
        }}</span>
      </RouterLink>
      <RouterLink
        v-if="nav.user?.membershipRole === 'owner'"
        to="/admin/requests"
        class="sidebar-request-link"
        :aria-current="route.path === '/admin/requests' ? 'page' : undefined"
        @click="emit('navigate')"
      >
        <AppIcon name="mail" :size="20" /><span>{{ t('requests.title') }}</span
        ><span v-if="nav.pendingRequests" class="unread-badge">{{ nav.pendingRequests }}</span>
      </RouterLink>
      <RouterLink
        v-if="nav.user?.membershipRole === 'owner'"
        to="/admin/administrators"
        class="sidebar-request-link"
        :aria-current="route.path === '/admin/administrators' ? 'page' : undefined"
        @click="emit('navigate')"
        ><AppIcon name="users" :size="20" /><span>{{ t('administrators.title') }}</span></RouterLink
      >
    </nav>
    <PersonalSettingsNav @navigate="emit('navigate')" />
    <AdminAccount
      v-if="nav.user"
      :user="nav.user"
      :busy="busy"
      @navigate="emit('navigate')"
      @logout="emit('logout')"
    />
  </div>
  <div v-else class="admin-sidebar-content" role="status">{{ t('nav.loading') }}</div>
</template>
