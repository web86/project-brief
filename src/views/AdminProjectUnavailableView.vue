<script setup>
import { useRoute } from 'vue-router'
const route = useRoute()
import { t } from '../i18n/index.js'
</script>
<template>
  <div class="empty-state page-empty">
    <h1>
      {{
        t(route.query.reason === 'permission' ? 'permissions.deniedTitle' : 'nav.unavailableTitle')
      }}
    </h1>
    <p>
      {{
        t(
          route.query.reason === 'permission'
            ? 'permissions.createOwnerOnly'
            : 'nav.unavailableBody',
        )
      }}
    </p>
    <RouterLink to="/admin" class="button primary">{{ t('nav.allProjects') }}</RouterLink>
  </div>
</template>
