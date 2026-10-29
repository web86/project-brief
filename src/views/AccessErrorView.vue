<script setup>
import { t } from '../i18n/index.js'

import { computed } from 'vue'
import { useRoute } from 'vue-router'
const route = useRoute()
const message = computed(
  () =>
    ({
      sso: t('sso.failed'),
      expired: t('ui.thisLinkHasExpired'),
      unavailable: t('ui.projectUnavailable'),
      session: t('ui.yourSessionHasEndedOpenYourPersonalLink'),
    })[route.query.reason] || t('ui.thisLinkIsInvalidOrHasBeenRevoked'),
)
const fallbackProject = computed(() =>
  route.query.reason === 'sso' &&
  typeof route.query.project === 'string' &&
  /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(route.query.project)
    ? route.query.project
    : null,
)
</script>
<template>
  <div class="empty-state page-empty">
    <h1>{{ t('ui.noAccessToThisProject') }}</h1>
    <p>{{ message }}</p>
    <RouterLink v-if="fallbackProject" :to="'/project/' + fallbackProject" class="button primary">{{
      t('sso.magic')
    }}</RouterLink>
    <p v-else>{{ t('ui.askTheDeveloperForANewLink') }}</p>
  </div>
</template>
