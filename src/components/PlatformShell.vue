<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api/client.js'
import { t } from '../i18n/index.js'
import { createAdminDrawer } from '../utils/adminDrawer.js'
import PlatformNavigation from './PlatformNavigation.vue'
import AppIcon from './AppIcon.vue'
import BrandLogo from './BrandLogo.vue'
const route = useRoute(),
  dialog = ref(null),
  trigger = ref(null),
  canManagePlans = ref(!!route.meta.planManagement)
const drawer = createAdminDrawer({ dialog: () => dialog.value, trigger: () => trigger.value })
const collapsed = ref(false)
watch(
  () => route.fullPath,
  () => drawer.close(false),
)
onMounted(async () => {
  if (route.meta.planManagement) return
  try {
    canManagePlans.value = (await api.request('/api/platform/plan-access')).canManage
  } catch {}
})
onBeforeUnmount(() => drawer.close(false))
</script>
<template>
  <div class="platform-shell" :class="{ 'platform-collapsed': collapsed }">
    <header class="platform-mobile-bar">
      <button
        ref="trigger"
        class="icon-button"
        :aria-label="t('nav.openMenu')"
        :aria-expanded="drawer.open.value"
        @click="drawer.show"
      >
        <AppIcon name="menu" />
      </button>
      <BrandLogo compact :size="24" /><strong class="platform-mobile-title">{{
        t('platform.title')
      }}</strong>
    </header>
    <aside class="platform-desktop-sidebar">
      <button
        class="platform-collapse text-button"
        :aria-expanded="!collapsed"
        :aria-label="t('platform.title')"
        @click="collapsed = !collapsed"
      >
        {{ collapsed ? '»' : '«' }}
      </button>
      <RouterLink
        v-if="collapsed"
        to="/platform"
        class="platform-compact-brand"
        aria-label="Dev2Done"
        ><BrandLogo compact :size="22"
      /></RouterLink>
      <PlatformNavigation v-show="!collapsed" :can-manage-plans="canManagePlans" />
    </aside>
    <dialog
      ref="dialog"
      class="platform-drawer"
      @keydown="drawer.keydown"
      @click="drawer.backdrop"
      @cancel.prevent="drawer.close()"
    >
      <button class="text-button" @click="drawer.close()">{{ t('platform.close') }}</button>
      <PlatformNavigation :can-manage-plans="canManagePlans" @navigate="drawer.close()" />
    </dialog>
    <main id="main-content" tabindex="-1" class="platform-main"><slot /></main>
  </div>
</template>
<style>
.platform-shell {
  display: grid;
  grid-template-columns: 260px minmax(0, 1fr);
  min-height: 100dvh;
}
.platform-desktop-sidebar {
  position: sticky;
  top: 0;
  height: 100dvh;
  overflow-y: auto;
  border-right: 1px solid var(--border);
  background: var(--surface);
  padding: 18px;
}
.platform-navigation {
  min-height: calc(100dvh - 70px);
}
.platform-navigation h2 {
  margin: 20px 0 8px;
  font-size: 12px;
  color: var(--text-subtle);
}
.platform-navigation a[aria-current='page'] {
  background: var(--action-soft, var(--surface-alt));
  color: var(--action);
  font-weight: 650;
}
.platform-nav-footer {
  margin-top: auto;
  display: grid;
  gap: 16px;
  padding-top: 24px;
}
.platform-preferences {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.platform-main {
  min-width: 0;
}
.platform-main .platform-page {
  width: 100%;
  max-width: 1440px;
  margin: 0 auto;
  padding: 28px;
}
.platform-collapse {
  display: block;
  margin-left: auto;
}
.platform-collapsed {
  grid-template-columns: 52px minmax(0, 1fr);
}
.platform-collapsed .platform-desktop-sidebar {
  padding: 12px 6px;
}
.platform-mobile-bar {
  display: none;
}
.platform-drawer {
  position: fixed;
  inset: 0 auto 0 0;
  margin: 0;
  padding: 20px;
  width: min(310px, 90vw);
  max-height: 100dvh;
  height: 100dvh;
  border: 0;
  background: var(--surface);
  color: var(--text);
}
.platform-drawer::backdrop {
  background: rgb(0 0 0 / 0.45);
}
@media (max-width: 960px) {
  .platform-shell,
  .platform-shell.platform-collapsed {
    display: block;
  }
  .platform-desktop-sidebar {
    display: none;
  }
  .platform-mobile-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--border);
    background: var(--surface);
  }
  .platform-main .platform-page {
    padding: 20px 16px;
  }
}
</style>

<style>
.platform-compact-brand {
  display: flex;
  justify-content: center;
  padding-block: 12px;
}
.platform-mobile-title {
  min-width: 0;
  font-size: 14px;
  line-height: 1.3;
}
</style>
