<script setup>
import BrandLogo from './BrandLogo.vue'
import { useWorkspaceAppearance } from '../services/workspaceAppearance.js'
defineProps({ compact: Boolean })
const { brand } = useWorkspaceAppearance()
</script>
<template>
  <span
    v-if="brand?.logoUrl || brand?.companyName"
    class="workspace-brand-logo"
    :title="brand.companyName"
  >
    <img v-if="brand.logoUrl" :src="brand.logoUrl" :alt="brand.companyName || ''" />
    <strong v-if="brand.companyName && !compact">{{ brand.companyName }}</strong>
  </span>
  <BrandLogo v-else :compact="compact" />
</template>
