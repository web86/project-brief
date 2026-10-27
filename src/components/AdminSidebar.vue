<script setup>
import BrandLogo from './BrandLogo.vue'
import SidebarScroll from './SidebarScroll.vue'
import { canCreateProjects } from '../services/projectPermissions.js'
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { t, formatApiError } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
import UnreadBadge from './UnreadBadge.vue'
import ProjectSidebar from './ProjectSidebar.vue'
import AdminAccount from './AdminAccount.vue'
defineProps({ busy: Boolean, trashBusy: Boolean, trashOpen: Boolean })
const emit = defineEmits(['navigate', 'logout', 'trash'])
const nav = useAdminNavigationStore(),
  router = useRouter(),
  route = useRoute()
const activeProject = computed(() => route.params.uuid)
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
    <SidebarScroll v-if="nav.workspace">
      <div class="sidebar-project-group">
        <nav
          class="sidebar-projects"
          :aria-label="t('nav.projects')"
          :aria-busy="nav.loading || nav.refreshing"
        >
          <h2>
            <RouterLink
              :to="nav.projectsPath"
              class="sidebar-project-heading"
              :aria-current="route.path === '/admin' ? 'page' : undefined"
              @click="emit('navigate')"
              ><AppIcon name="arrow" :size="16" /><span>{{
                t('nav.allProjects')
              }}</span></RouterLink
            >
          </h2>
          <div
            v-if="nav.loading && !nav.projects.length"
            class="sidebar-skeleton"
            role="status"
            :aria-label="t('nav.loading')"
          >
            <span v-for="n in 4" :key="n" />
          </div>
          <p v-else-if="!nav.projects.length" class="small muted">{{ t('nav.noProjects') }}</p>
          <RouterLink
            v-for="project in nav.projects"
            :key="project.id"
            :to="`/admin/projects/${project.id}/brief`"
            class="sidebar-project"
            :class="{ active: activeProject === project.id }"
            :aria-current="activeProject === project.id ? 'page' : undefined"
            @click="emit('navigate')"
          >
            <AppIcon name="grid" :size="20" /><span class="sidebar-project-name">{{
              project.name
            }}</span
            ><UnreadBadge :count="project.unreadCount || 0" />
          </RouterLink>
          <div v-if="nav.listError" class="sidebar-error" role="alert">
            <p>{{ formatApiError(nav.listError) }}</p>
            <button type="button" class="text-button" @click="nav.refreshProjects()">
              {{ t('ui.tryAgain') }}
            </button>
          </div>
          <button
            v-if="nav.sidebarPage < nav.sidebarLastPage"
            type="button"
            class="text-button sidebar-more"
            :disabled="nav.refreshing"
            @click="nav.moreProjects()"
          >
            {{ t('branding.more') }}
          </button>
        </nav>
      </div>
    </SidebarScroll>
    <nav
      class="sidebar-general personal-settings-nav"
      :aria-label="t('centralNotifications.mySettings')"
    >
      <h2>{{ t('centralNotifications.mySettings') }}</h2>
      <RouterLink
        v-if="nav.workspace && nav.user?.membershipRole === 'owner'"
        to="/admin/settings/integrations"
        :aria-current="route.path === '/admin/settings/integrations' ? 'page' : undefined"
        @click="emit('navigate')"
        ><AppIcon name="settings" :size="20" /><span>{{
          t('integrations.title')
        }}</span></RouterLink
      >
      <RouterLink
        v-if="nav.workspace && nav.user?.membershipRole === 'owner'"
        to="/admin/settings/branding"
        :aria-current="route.path === '/admin/settings/branding' ? 'page' : undefined"
        @click="emit('navigate')"
        ><AppIcon name="palette" :size="20" /><span>{{ t('branding.title') }}</span></RouterLink
      >
      <RouterLink
        to="/admin/settings/notifications"
        :aria-current="route.path === '/admin/settings/notifications' ? 'page' : undefined"
        @click="emit('navigate')"
        ><AppIcon name="bell" :size="20" /><span>{{ t('nav.notifications') }}</span></RouterLink
      >
    </nav>
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
