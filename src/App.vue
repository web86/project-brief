<script setup>
import { nextTick, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useProjectStore } from './stores/project'
import ModeSwitcher from './components/ModeSwitcher.vue'
import AppIcon from './components/AppIcon.vue'
const store = useProjectStore()
const route = useRoute()
store.loadFromStorage()
watch(() => route.fullPath, async () => {
  document.title = `${route.meta.title || 'Ваш проект'} — Project Brief`
  await nextTick()
  document.getElementById('main-content')?.focus({ preventScroll: true })
})
</script>
<template>
  <a class="skip-link" href="#main-content">Перейти к содержимому</a><div class="app-shell"><header class="app-topbar"><div class="topbar-inner"><RouterLink to="/" class="brand" aria-label="Project Brief — на страницу проекта"><span class="brand-mark">p<span class="brand-dot">.</span></span><span>project<span class="brand-light">brief</span></span><span class="beta-tag">MVP</span></RouterLink><ModeSwitcher /></div></header><div class="workspace"><nav class="workspace-nav" aria-label="Навигация проекта"><RouterLink to="/" class="workspace-link"><AppIcon name="grid" :size="17" />Мой проект</RouterLink><span class="workspace-caption">Место для ваших идей</span></nav><div v-if="store.storageError" class="storage-warning" role="alert"><AppIcon name="info" :size="22" /><div><strong>Не удалось сохранить проект</strong><p>{{ store.storageError }}</p></div><button v-if="!store.storageBlocked" class="button secondary" @click="store.saveToStorage()">Повторить сохранение</button></div><main id="main-content" tabindex="-1"><RouterView /></main></div></div>
</template>
