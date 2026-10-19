<script setup>
import { t } from '../i18n/index.js'
import { theme, THEME_MODES } from '../services/theme.js'
import AppIcon from './AppIcon.vue'

const { mode, resolved } = theme
</script>
<template>
  <label class="theme-switcher">
    <AppIcon :name="resolved === 'dark' ? 'moon' : 'sun'" :size="18" />
    <span class="sr-only">{{ t('appearance.theme') }}</span>
    <select :value="mode" @change="theme.select($event.target.value)">
      <option v-for="value in THEME_MODES" :key="value" :value="value">
        {{ t(`appearance.${value}`) }}
      </option>
    </select>
  </label>
</template>
