<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t } from '../i18n/index.js'
import BrandLogo from './PortalBrandLogo.vue'
import LanguageSwitcher from './LanguageSwitcher.vue'
import ThemeSwitcher from './ThemeSwitcher.vue'
const props = defineProps({ canManagePlans: Boolean })
const emit = defineEmits(['navigate'])
const route = useRoute(),
  router = useRouter(),
  busy = ref(false)
const active = (section) =>
  route.path === '/platform' && (route.query.section || 'overview') === section
async function logout() {
  busy.value = true
  try {
    await api.request('/api/admin/logout', { method: 'POST' })
    api.resetCsrf()
    await router.replace('/admin/login')
  } finally {
    busy.value = false
  }
}
</script>
<template>
  <div class="admin-sidebar-content platform-navigation">
    <RouterLink to="/platform" class="brand" @click="emit('navigate')"><BrandLogo /></RouterLink>
    <nav class="personal-settings-nav" :aria-label="t('platform.title')">
      <h2>{{ t('platform.title') }}</h2>
      <RouterLink
        v-for="section in ['overview', 'workspaces', 'usage', 'audit']"
        :key="section"
        :to="{ path: '/platform', query: { section } }"
        :aria-current="active(section) ? 'page' : undefined"
        @click="emit('navigate')"
        >{{ t('platform.' + section) }}</RouterLink
      >
      <template v-if="props.canManagePlans">
        <h2>{{ t('planManagement.title') }}</h2>
        <RouterLink
          v-for="[key, path] in [
            ['catalog', '/platform/plans'],
            ['inventory', '/platform/plans/inventory'],
            ['policies', '/platform/plans/policies'],
            ['rules', '/platform/plans/transitions'],
          ]"
          :key="key"
          :to="path"
          :aria-current="
            route.path === path || (key === 'catalog' && route.meta.planSection === 'editor')
              ? 'page'
              : undefined
          "
          @click="emit('navigate')"
          >{{ key === 'inventory' ? t('inventory.title') : t('planManagement.' + key) }}</RouterLink
        >
      </template>
    </nav>
    <div class="platform-nav-footer">
      <RouterLink to="/admin" class="sidebar-all-projects" @click="emit('navigate')">{{
        t('nav.allProjects')
      }}</RouterLink>
      <div class="platform-preferences"><LanguageSwitcher /><ThemeSwitcher /></div>
      <button class="text-button" :disabled="busy" @click="logout">
        {{ t('platform.signOut') }}
      </button>
    </div>
  </div>
</template>
