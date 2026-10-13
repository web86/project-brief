<script setup>
import { t } from '../i18n/index.js'
</script>
<template>
  <div class="empty-state page-empty">
    <h1>{{ t('nav.unavailableTitle') }}</h1>
    <p>{{ t('nav.unavailableBody') }}</p>
    <RouterLink to="/admin" class="button primary">{{ t('nav.allProjects') }}</RouterLink>
  </div>
</template>
