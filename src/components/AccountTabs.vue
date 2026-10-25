<script setup>
import { accountMenu } from '../services/adminMenu.js'
import AppIcon from './AppIcon.vue'
import { t } from '../i18n/index.js'
import { useRoute } from 'vue-router'
const route = useRoute()
</script>
<template>
  <nav class="account-tabs" :aria-label="t('account.title')">
    <RouterLink
      v-for="item in accountMenu"
      :key="item.to"
      :to="item.to"
      :aria-current="route.path === item.to ? 'page' : undefined"
      ><AppIcon :name="item.icon" :size="20" /><span>{{ t(item.label) }}</span></RouterLink
    >
  </nav>
</template>
