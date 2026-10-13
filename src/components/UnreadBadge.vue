<script setup>
import { t } from '../i18n/index.js'
defineProps({ count: { type: Number, default: 0 } })
</script>
<template>
  <span v-if="count > 0" class="unread-badge" :aria-label="t('nav.unread', { count }, count)">
    {{ count > 99 ? '99+' : count }}
  </span>
</template>
