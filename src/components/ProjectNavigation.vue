<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
import { canOpenProjectSection, hasProjectPermission } from '../services/projectPermissions.js'
import { projectMenu } from '../services/adminMenu.js'
defineProps({ vertical: Boolean, trashBusy: Boolean, trashOpen: Boolean })
const emit = defineEmits(['navigate', 'trash'])
const route = useRoute(),
  nav = useAdminNavigationStore()
const unreadComments = computed(
  () => nav.projects.find((project) => project.id === route.params.uuid)?.unreadCommentCount || 0,
)
const sections = computed(() =>
  projectMenu.filter((item) =>
    canOpenProjectSection(
      nav.projectForRoute(route.params.uuid),
      item.section,
      nav.user?.membershipRole === 'owner',
    ),
  ),
)
</script>
<template>
  <nav
    :class="vertical ? 'project-panel-nav' : 'project-subnav'"
    :aria-label="t('nav.projectNavigation')"
  >
    <RouterLink
      v-for="{ section, icon } in sections"
      :key="section"
      :to="`/admin/projects/${route.params.uuid}/${section}`"
      :aria-current="route.meta.projectSection === section ? 'page' : undefined"
      :class="{ active: route.meta.projectSection === section }"
      @click="emit('navigate')"
    >
      <AppIcon :name="icon" :size="20" /><span class="nav-item-label">{{
        t(section === 'team' ? 'team.title' : 'nav.' + section)
      }}</span>
      <span
        v-if="section === 'discussions' && unreadComments"
        class="discussion-badge"
        :aria-label="t('discussions.unreadLabel', { count: unreadComments })"
        >{{ unreadComments }}</span
      >
    </RouterLink>
    <button
      type="button"
      v-if="hasProjectPermission(nav.projectForRoute(route.params.uuid), 'manage_trash')"
      class="project-trash-link"
      :disabled="trashBusy"
      :aria-expanded="trashOpen"
      aria-haspopup="dialog"
      @click="emit('trash')"
    >
      <AppIcon name="trash" :size="20" /><span>{{ t('management.trash') }}</span>
    </button>
  </nav>
</template>
