<script setup>
import { locale, t, LANGUAGE_CATALOG } from '../i18n/index.js'
import { useProjectStore } from '../stores/project.js'
const store = useProjectStore()
</script>
<template>
  <label class="language-switcher">
    <span class="sr-only">{{ t('flow.language') }}</span>
    <select :value="locale" @change="store.selectLocale($event.target.value)">
      <option
        v-for="language in LANGUAGE_CATALOG"
        :key="language.code"
        :value="language.code"
        :lang="language.code"
      >
        {{ language.name }}
      </option>
    </select>
  </label>
</template>
