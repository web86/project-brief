<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import BrandLogo from './BrandLogo.vue'
import {
  useWorkspaceAppearance,
  isEntryRoute,
  entryBrandingReady,
  isDocumentRoute,
  documentBrandingReady,
} from '../services/workspaceAppearance.js'
defineProps({ compact: Boolean })
const route = useRoute(),
  nav = useAdminNavigationStore()
const switching = computed(
  () =>
    (route.meta.adminShell && nav.switching) ||
    (isEntryRoute(route) && !entryBrandingReady.value) ||
    (isDocumentRoute(route) && !documentBrandingReady.value),
)
const { brand } = useWorkspaceAppearance()
</script>
<template>
  <span v-if="switching" class="workspace-brand-pending" aria-hidden="true" />
  <span
    v-else-if="brand?.logoUrl || brand?.companyName"
    class="workspace-brand-logo"
    :title="brand.companyName"
  >
    <img v-if="brand.logoUrl" :src="brand.logoUrl" :alt="brand.companyName || ''" />
    <strong v-if="brand.companyName && !compact">{{ brand.companyName }}</strong>
  </span>
  <BrandLogo v-else :compact="compact" />
</template>

<style scoped>
.workspace-brand-pending {
  display: inline-block;
  min-width: 40px;
  height: 32px;
}
</style>
