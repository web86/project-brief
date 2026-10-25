<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
defineProps({ vertical: Boolean, trashBusy: Boolean, trashOpen: Boolean })
const emit = defineEmits(['navigate', 'trash'])
const route = useRoute(),
  nav = useAdminNavigationStore()
const unreadComments = computed(
  () => nav.projects.find((project) => project.id === route.params.uuid)?.unreadCommentCount || 0,
)
const sections = [
  'brief',
  'discussions',
  'clients',
  'structure',
  'notifications',
  'settings',
  'export',
]
</script>
<template>
  <nav
    :class="vertical ? 'project-panel-nav' : 'project-subnav'"
    :aria-label="t('nav.projectNavigation')"
  >
    <RouterLink
      v-for="section in sections"
      :key="section"
      :to="`/admin/projects/${route.params.uuid}/${section}`"
      :aria-current="route.meta.projectSection === section ? 'page' : undefined"
      :class="{ active: route.meta.projectSection === section }"
      @click="emit('navigate')"
    >
      {{ t('nav.' + section) }}
      <span
        v-if="section === 'discussions' && unreadComments"
        class="discussion-badge"
        :aria-label="t('discussions.unreadLabel', { count: unreadComments })"
        >{{ unreadComments }}</span
      >
    </RouterLink>
    <button
      type="button"
      class="project-trash-link"
      :disabled="trashBusy"
      :aria-expanded="trashOpen"
      aria-haspopup="dialog"
      @click="emit('trash')"
    >
      <AppIcon name="trash" :size="17" /><span>{{ t('management.trash') }}</span>
    </button>
  </nav>
</template>
