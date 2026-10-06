<script setup>
import { t } from '../i18n/index.js'

import { computed } from 'vue'
import { useRoute } from 'vue-router'
const route = useRoute()
const message = computed(
  () =>
    ({
      expired: t('ui.thisLinkHasExpired'),
      unavailable: t('ui.projectUnavailable'),
      session: t('ui.yourSessionHasEndedOpenYourPersonalLink'),
    })[route.query.reason] || t('ui.thisLinkIsInvalidOrHasBeenRevoked'),
)
</script>
<template>
  <div class="empty-state page-empty">
    <h1>{{ t('ui.noAccessToThisProject') }}</h1>
    <p>{{ message }}</p>
    <p>{{ t('ui.askTheDeveloperForANewLink') }}</p>
  </div>
</template>
