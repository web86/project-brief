<script setup>
import { useRoute } from 'vue-router'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { computed } from 'vue'
import {
  useWorkspaceAppearance,
  isEntryRoute,
  entryBrandingReady,
  isDocumentRoute,
  documentBrandingReady,
} from '../services/workspaceAppearance.js'
import { safeFooterUrl } from '../utils/agreementEditor.js'
import { t, locale } from '../i18n/index.js'
const props = defineProps({ compact: Boolean, branding: Object })
const route = useRoute(),
  nav = useAdminNavigationStore()
const switching = computed(
  () =>
    (route.meta.adminShell && nav.switching) ||
    (isEntryRoute(route) && !entryBrandingReady.value) ||
    (isDocumentRoute(route) && !documentBrandingReady.value),
)
const { brand } = useWorkspaceAppearance()
const current = computed(() => props.branding || brand.value || {})
const agreement = computed(() => safeFooterUrl(current.value.agreementUrl))
const support = computed(() => safeFooterUrl(current.value.supportEmail, true))
const company = computed(() => safeFooterUrl(current.value.companyUrl))
const year = new Date().getFullYear()
</script>
<template>
  <footer class="app-footer" :class="{ 'is-compact': compact, 'is-switching': switching }">
    <span
      >© {{ year }}
      <a v-if="company" :href="company" rel="noopener noreferrer">{{
        current.companyName || 'Project Brief'
      }}</a
      ><template v-else>{{ current.companyName || 'Project Brief' }}</template></span
    >
    <nav v-if="agreement || support" :aria-label="t('agreement.footer')">
      <RouterLink v-if="agreement" :to="{ path: agreement, query: { lang: locale } }">{{
        t('agreement.rules')
      }}</RouterLink>
      <a v-if="support" :href="support">{{ t('agreement.support') }}</a>
    </nav>
  </footer>
</template>
<style>
.app-footer.is-switching {
  visibility: hidden;
}
.app-footer {
  margin-top: auto;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px 24px;
  padding: 16px 28px max(16px, env(safe-area-inset-bottom));
  border-top: 1px solid var(--border);
  color: var(--muted);
  font-size: 0.78rem;
  line-height: 1.6;
  overflow-wrap: anywhere;
}
.app-footer nav {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 20px;
}
.app-footer a {
  color: inherit;
  text-underline-offset: 3px;
}
.app-footer.is-compact {
  padding-inline: 20px;
}
.app-shell:not(.client-shell):not(.task-workspace-shell) {
  display: flex;
  flex-direction: column;
  min-height: 100dvh;
}
.admin-main {
  flex: 1;
  width: 100%;
  margin: 0 auto;
}
.app-shell > .workspace {
  flex: 1;
}
.admin-content {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
}
@media (min-width: 961px) {
  .app-shell.client-shell {
    grid-template-rows: auto 1fr auto;
  }
  .client-shell > .app-footer {
    grid-column: 2;
    grid-row: 3;
  }
  .client-shell > .client-sidebar {
    grid-row: 1 / 4;
  }
  .task-workspace-shell > .app-footer {
    grid-column: -2 / -1;
  }
}
@media (max-width: 960px) {
  .app-shell.client-shell {
    display: flex;
    flex-direction: column;
    min-height: 100dvh;
  }
}
@media (max-width: 600px) {
  .app-footer {
    padding-inline: 18px;
    gap: 6px;
  }
  .app-footer nav {
    width: 100%;
  }
}
</style>
