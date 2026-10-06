<script setup>
import { t } from '../i18n/index.js'

import { useProjectStore } from '../stores/project'
import AppIcon from './AppIcon.vue'
const store = useProjectStore()
</script>

<template>
  <div class="mode-switch" role="group" :aria-label="t('ui.viewMode')">
    <button
      :class="{ active: !store.isDeveloper }"
      :aria-pressed="!store.isDeveloper"
      @click="store.setMode('client')"
    >
      <AppIcon name="user" :size="15" /><span>{{ t('ui.clientMode') }}</span>
    </button>
    <button
      :class="{ active: store.isDeveloper }"
      :aria-pressed="store.isDeveloper"
      @click="store.setMode('developer')"
    >
      <AppIcon name="code" :size="16" /><span>{{ t('ui.developerMode') }}</span>
    </button>
  </div>
</template>
