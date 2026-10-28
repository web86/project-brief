import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { useProjectStore } from '../stores/project.js'
import { brandTokens } from './branding.js'
import { theme } from './theme.js'

export function activeWorkspaceBrand(route, admin, project) {
  if (route.meta.adminShell) {
    return !route.meta.personalSettings &&
      route.path !== '/admin/unavailable' &&
      admin.ready &&
      !admin.switching
      ? admin.workspace?.branding || null
      : null
  }
  return route.meta.apiProject && !route.meta.admin && project.currentClient
    ? project.project.branding || null
    : null
}
export function useWorkspaceAppearance() {
  const route = useRoute(),
    admin = useAdminNavigationStore(),
    project = useProjectStore()
  const brand = computed(() => activeWorkspaceBrand(route, admin, project))
  const tokens = computed(() =>
    brandTokens(brand.value?.primaryColor, theme.resolved.value === 'dark'),
  )
  return { brand, tokens }
}
