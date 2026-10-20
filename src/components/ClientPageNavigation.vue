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
  <div class="client-page-navigation">
    <RouterLink :to="store.projectPath" class="client-back-link"
      ><AppIcon name="arrow" :size="17" />{{ t('centralNotifications.backToIdeas') }}</RouterLink
    >
    <nav class="client-horizontal-nav" :aria-label="t('clientWorkspace.menu')">
      <RouterLink
        v-for="link in links"
        :key="link.path"
        :to="link.path"
        :class="{ active: route.path === link.path }"
        :aria-current="route.path === link.path ? 'page' : undefined"
      >
        {{ link.label }}<UnreadBadge v-if="link.unread" :count="link.unread" />
      </RouterLink>
    </nav>
  </div>
</template>
