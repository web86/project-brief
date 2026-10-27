<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import BrandLogo from './BrandLogo.vue'
import { useProjectStore } from '../stores/project.js'
defineProps({ compact: Boolean })
const store = useProjectStore(),
  route = useRoute()
const brand = computed(() =>
  !store.isDeveloper && store.currentClient && route.meta.apiProject && !route.meta.admin
    ? store.project.branding
    : null,
)
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
