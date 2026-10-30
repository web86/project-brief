import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { useProjectStore } from '../stores/project.js'
import { brandTokens } from './branding.js'
import { api } from '../api/client.js'
import { theme } from './theme.js'

export const documentBranding = ref(null)
export const documentBrandingReady = ref(false)
export const isDocumentRoute = (route) => !!(route.meta.agreement || route.meta.publicDocument)
export function setDocumentBranding(branding, ready = true) {
  documentBranding.value = branding
  documentBrandingReady.value = ready
}
export const entryBranding = ref(null)
export const entryBrandingReady = ref(false)
let entryRevision = 0
export function setEntryBranding(branding) {
  entryRevision++
  entryBranding.value = branding
  entryBrandingReady.value = true
}
export async function loadEntryBranding(projectUuid) {
  const current = ++entryRevision
  entryBranding.value = null
  entryBrandingReady.value = false
  try {
    const result = await api.request(
      '/api/entry/branding' + (projectUuid ? '?project=' + encodeURIComponent(projectUuid) : ''),
    )
    if (current === entryRevision) entryBranding.value = result.branding
  } finally {
    if (current === entryRevision) entryBrandingReady.value = true
  }
}
export const isEntryRoute = (route) =>
  ['/invitation', '/admin/security', '/admin/login'].includes(route.path) ||
  route.meta.clientLogin === true
export function activeWorkspaceBrand(route, admin, project) {
  if (route.meta.adminShell) {
    return route.path !== '/admin/unavailable' && admin.ready && !admin.switching
      ? admin.workspace?.branding || null
      : null
  }
  if (isDocumentRoute(route)) return documentBranding.value
  if (isEntryRoute(route)) return entryBranding.value
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
