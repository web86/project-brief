<script setup>
import { nextTick, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useProjectStore } from './stores/project'
import ModeSwitcher from './components/ModeSwitcher.vue'
import { api } from './api/client'
import AppIcon from './components/AppIcon.vue'
const store = useProjectStore()
const route = useRoute()
const router = useRouter()
async function logout() {
  await store.flushDeveloperData()
  try {
    await api.request('/api/admin/logout', { method: 'POST' })
    api.resetCsrf()
    store.admin = null
    store.tasks = []
    await router.push('/admin/login')
  } catch (error) {
    store.apiError = error.message
  }
}
watch(
  () => store.sessionLost,
  (lost) => {
    if (lost) {
      const destination = store.admin ? '/admin/login' : '/access-error?reason=session'
      store.admin = null
      store.tasks = []
      store.developerDrafts = {}
      store.currentClient = null
      router.replace(destination)
    }
  },
)
store.loadFromStorage()
watch(
  () => route.fullPath,
  async () => {
    document.title = `${route.meta.title || 'Ваш проект'} — Project Brief`
    await nextTick()
    document.getElementById('main-content')?.focus({ preventScroll: true })
  },
)
</script>
<template>
  <a class="skip-link" href="#main-content">Перейти к содержимому</a>
  <div class="app-shell">
    <header class="app-topbar">
      <div class="topbar-inner">
        <RouterLink
          :to="
            store.apiMode
              ? store.admin
                ? '/admin'
                : store.project.id
                  ? store.projectPath
                  : '/admin/login'
              : '/'
          "
          class="brand"
          aria-label="Project Brief — на страницу проекта"
          ><span class="brand-mark">p<span class="brand-dot">.</span></span
          ><span>project<span class="brand-light">brief</span></span></RouterLink
        ><ModeSwitcher v-if="!store.apiMode" />
        <nav v-else-if="store.admin" class="admin-nav" aria-label="Администратор">
          <RouterLink to="/admin">Проекты</RouterLink
          ><button class="text-button" @click="logout">Выйти</button>
        </nav>
        <span v-else-if="store.currentClient" class="current-client-name">{{
          store.currentClient.name
        }}</span>
      </div>
    </header>
    <div class="workspace">
      <div v-if="store.storageError" class="storage-warning" role="alert">
        <AppIcon name="info" :size="22" />
        <div>
          <strong>{{
            store.storageBlocked
              ? 'Не удалось открыть сохранённый проект'
              : 'Не удалось сохранить проект'
          }}</strong>
          <p>{{ store.storageError }}</p>
        </div>
        <button
          v-if="!store.storageBlocked"
          class="button secondary"
          @click="store.saveToStorage()"
        >
          Повторить сохранение
        </button>
      </div>
      <div v-if="store.apiError" class="storage-warning" role="alert">
        <p>{{ store.apiError }}</p>
        <button
          v-if="Object.keys(store.developerDrafts).length"
          class="button secondary"
          @click="store.flushDeveloperData()"
        >
          Повторить сохранение
        </button>
      </div>
      <p v-if="store.apiLoading" role="status" class="muted">Открываем проект…</p>
      <p
        v-if="store.apiMode && Object.keys(store.developerDrafts).length"
        role="status"
        class="muted small"
      >
        {{
          store.savingDeveloper
            ? 'Сохраняем изменения…'
            : store.apiError
              ? 'Изменения ещё не сохранены.'
              : 'Изменения будут сохранены автоматически…'
        }}
      </p>
      <main id="main-content" tabindex="-1"><RouterView /></main>
    </div>
  </div>
</template>
