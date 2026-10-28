<script setup>
import BrandLogo from './PortalBrandLogo.vue'
import PersonalSettingsNav from './PersonalSettingsNav.vue'
import SidebarScroll from './SidebarScroll.vue'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
import ProjectNavigation from './ProjectNavigation.vue'
import AdminAccount from './AdminAccount.vue'
defineProps({
  project: { type: Object, required: true },
  task: Boolean,
  busy: Boolean,
  trashBusy: Boolean,
  trashOpen: Boolean,
})
const emit = defineEmits(['navigate', 'logout', 'trash'])
const nav = useAdminNavigationStore()
</script>
<template>
  <div class="project-sidebar-content" :class="{ 'admin-sidebar-content': !task }">
    <RouterLink
      v-if="!task"
      :to="nav.projectsPath"
      class="brand"
      :aria-label="t('ui.projectBriefOpenTheProject')"
      @click="emit('navigate')"
    >
      <BrandLogo />
    </RouterLink>
    <RouterLink
      :to="nav.projectsPath"
      class="task-all-projects project-all-projects"
      @click="emit('navigate')"
    >
      <AppIcon name="arrow" :size="20" /><span>{{ t('nav.allProjects') }}</span>
    </RouterLink>
    <SidebarScroll
      ><section class="task-project-management">
        <h2 v-if="task">{{ t('taskWorkspace.manageProject') }}</h2>
        <p class="project-sidebar-name" :title="project.name">{{ project.name }}</p>
        <ProjectNavigation
          vertical
          :trash-busy="trashBusy"
          :trash-open="trashOpen"
          @navigate="emit('navigate')"
          @trash="emit('trash')"
        /></section
    ></SidebarScroll>
    <div class="project-personal-navigation">
      <PersonalSettingsNav collapsible @navigate="emit('navigate')" />
      <AdminAccount
        @navigate="emit('navigate')"
        v-if="nav.user"
        :user="nav.user"
        :busy="busy"
        @logout="emit('logout')"
      />
    </div>
  </div>
</template>
