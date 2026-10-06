<script setup>
import { locale, t, SUPPORTED_LOCALES } from '../i18n/index.js'
import { useProjectStore } from '../stores/project.js'
const store = useProjectStore()
</script>
<template>
  <div class="language-switcher" role="group" :aria-label="t('flow.language')">
    <button
      v-for="language in SUPPORTED_LOCALES"
      :key="language"
      type="button"
      :aria-pressed="locale === language"
      :class="{ active: locale === language }"
      @click="store.selectLocale(language)"
    >
      {{ language.toUpperCase() }}
    </button>
  </div>
</template>
