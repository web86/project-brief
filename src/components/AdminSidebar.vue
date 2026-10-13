<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { t, formatApiError } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
import UnreadBadge from './UnreadBadge.vue'
const emit = defineEmits(['navigate'])
const nav = useAdminNavigationStore(),
  router = useRouter(),
  route = useRoute()
const activeProject = computed(() => route.params.uuid)
async function select(event) {
  const id = event.target.value
  await nav.switchWorkspace(id, router)
  event.target.value = nav.workspace?.id || ''
  emit('navigate')
}
</script>
<template>
  <div class="admin-sidebar-content">
    <RouterLink
      to="/admin"
      class="brand"
      :aria-label="t('ui.projectBriefOpenTheProject')"
      @click="emit('navigate')"
    >
      <span class="brand-mark">p<span class="brand-dot">.</span></span>
      <span>project<span class="brand-light">brief</span></span>
    </RouterLink>
    <label class="sidebar-workspace">
      <span>{{ t('nav.workspace') }}</span>
      <select
        :value="nav.workspace?.id || ''"
        :disabled="nav.loading || nav.switching || !nav.ready"
        @change="select"
      >
        <option v-if="!nav.workspace" value="">{{ t('nav.loading') }}</option>
        <option v-for="workspace in nav.workspaces" :key="workspace.id" :value="workspace.id">
          {{ workspace.name }}
        </option>
      </select>
    </label>
    <RouterLink
      to="/admin/projects/new"
      class="button primary sidebar-create"
      @click="emit('navigate')"
    >
      <AppIcon name="plus" :size="17" />{{ t('nav.newProject') }}
    </RouterLink>
    <nav
      class="sidebar-projects"
      :aria-label="t('nav.projects')"
      :aria-busy="nav.loading || nav.refreshing"
    >
      <h2>{{ t('nav.projects') }}</h2>
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
        <span class="sidebar-project-name">{{ project.name }}</span
        ><UnreadBadge :count="project.unreadCount || 0" />
      </RouterLink>
      <div v-if="nav.listError" class="sidebar-error" role="alert">
        <p>{{ formatApiError(nav.listError) }}</p>
        <button type="button" class="text-button" @click="nav.refreshProjects()">
          {{ t('ui.tryAgain') }}
        </button>
      </div>
    </nav>
    <nav class="sidebar-general" :aria-label="t('nav.general')">
      <RouterLink
        to="/admin"
        :aria-current="route.path === '/admin' ? 'page' : undefined"
        @click="emit('navigate')"
        >{{ t('nav.allProjects') }}</RouterLink
      >
    </nav>
    <footer class="sidebar-account" v-if="nav.user">
      <strong>{{ nav.user.name }}</strong>
      <span>{{ nav.user.membershipRole === 'owner' ? t('nav.owner') : t('nav.admin') }}</span>
      <slot name="logout" />
    </footer>
  </div>
</template>
