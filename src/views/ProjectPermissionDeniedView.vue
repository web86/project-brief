<script setup>
import { useRoute } from 'vue-router'
import { t } from '../i18n/index.js'
const route = useRoute()
</script>
<template>
  <div class="empty-state page-empty" role="status">
    <h2>{{ t('permissions.deniedTitle') }}</h2>
    <p>{{ t('permissions.deniedBody') }}</p>
    <RouterLink :to="`/admin/projects/${route.params.uuid}/brief`" class="button primary">{{
      t('nav.brief')
    }}</RouterLink>
  </div>
</template>
