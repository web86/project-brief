<script setup>
import { useRoute } from 'vue-router'
import { useProjectStore } from '../stores/project.js'
import { useClientNavigation } from '../services/clientNavigation.js'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
import UnreadBadge from './UnreadBadge.vue'
const route = useRoute(),
  store = useProjectStore(),
  links = useClientNavigation()
</script>
<template>
  <aside class="client-sidebar">
    <RouterLink
      :to="store.projectPath"
      class="brand"
      :aria-label="t('ui.projectBriefOpenTheProject')"
      ><span class="brand-mark">p<span class="brand-dot">.</span></span
      ><span class="brand-wordmark">project<span class="brand-light">brief</span></span></RouterLink
    >
    <p class="client-sidebar-project" :title="store.project.name">{{ store.project.name }}</p>
    <nav class="project-panel-nav" :aria-label="t('clientWorkspace.menu')">
      <RouterLink
        v-for="link in links"
        :key="link.path"
        :to="link.path"
        :class="{ active: route.path === link.path }"
        :aria-current="route.path === link.path ? 'page' : undefined"
        ><AppIcon :name="link.icon" :size="20" /><span>{{ link.label }}</span
        ><UnreadBadge v-if="link.unread" :count="link.unread"
      /></RouterLink>
    </nav>
    <footer class="sidebar-account">
      <AppIcon name="user" :size="22" />
      <div>
        <strong>{{ store.currentClient?.name }}</strong
        ><span>{{ t('clientWorkspace.role') }}</span>
      </div>
    </footer>
  </aside>
</template>
