<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t, locale, setManualLocale, formatApiError } from '../i18n/index.js'
import { appDestination } from '../services/session.js'
import { brandTokens } from '../services/branding.js'
import { theme } from '../services/theme.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import AgreementDocument from '../components/AgreementDocument.vue'
import AppFooter from '../components/AppFooter.vue'
import LoadingButton from '../components/LoadingButton.vue'
const route = useRoute(),
  router = useRouter()
const state = ref(null),
  error = ref(null),
  busy = ref(false)
const publicPage = computed(() => !!route.params.workspace)
const tokens = computed(() =>
  brandTokens(state.value?.workspace.branding?.primaryColor, theme.resolved.value === 'dark'),
)
const printDocument = () => window.print()
let revision = 0
async function load() {
  const current = ++revision
  state.value = null
  error.value = null
  try {
    const result = await api.request(
      publicPage.value
        ? `/api/public/workspaces/${route.params.workspace}/agreement`
        : '/api/admin/agreement',
    )
    if (current !== revision) return
    if (!publicPage.value && !result.required)
      return router.replace((await appDestination(api.request)) || '/app')
    state.value = result
  } catch (e) {
    if (current === revision) error.value = e
  }
}
async function accept() {
  if (busy.value) return
  busy.value = true
  error.value = null
  try {
    await api.request('/api/admin/agreement/accept', {
      method: 'POST',
      body: { revision: state.value.document.id, locale: locale.value },
    })
    useAdminNavigationStore().clear()
    await router.replace((await appDestination(api.request)) || '/app')
  } catch (e) {
    error.value = e
  } finally {
    busy.value = false
  }
}
async function logout() {
  if (busy.value) return
  busy.value = true
  try {
    await api.request('/api/admin/logout', { method: 'POST' })
    api.resetCsrf()
    useAdminNavigationStore().clear()
    await router.replace('/admin/login')
  } catch (e) {
    error.value = e
  } finally {
    busy.value = false
  }
}
watch(
  () => route.params.workspace,
  () => {
    if (['ru', 'en', 'tr', 'zh-CN'].includes(route.query.lang)) setManualLocale(route.query.lang)
    load()
  },
  { immediate: true },
)
</script>
<template>
  <div class="legal-layout" :style="tokens">
    <article class="legal-page surface">
      <p v-if="error" class="form-error" role="alert">
        {{ error.status === 409 ? t('agreement.conflict') : formatApiError(error) }}
        <button type="button" class="text-button" @click="load">{{ t('ui.tryAgain') }}</button>
      </p>
      <template v-if="state">
        <p class="muted">{{ state.workspace.branding?.companyName || state.workspace.name }}</p>
        <h1>{{ t('agreement.title') }}</h1>
        <p class="small muted">
          {{ t('agreement.version') }}:
          <time :datetime="state.document.date">{{ state.document.date }}</time>
        </p>
        <p v-if="!publicPage" class="muted">{{ t('agreement.acceptHint') }}</p>
        <AgreementDocument :blocks="state.document.texts[locale]" />
      </template>
      <div v-if="!publicPage" class="agreement-actions">
        <button class="button secondary" type="button" :disabled="busy" @click="logout">
          {{ t('ui.signOut') }}</button
        ><LoadingButton
          class="button primary"
          :busy="busy"
          :disabled="busy || !state"
          @click="accept"
          >{{ t('agreement.accept') }}</LoadingButton
        >
      </div>
      <button v-else-if="state" class="text-button agreement-actions" @click="printDocument">
        {{ t('agreement.print') }}
      </button>
    </article>
    <AppFooter compact :branding="state?.workspace.branding || {}" />
  </div>
</template>
<style scoped>
.legal-layout {
  display: flex;
  flex-direction: column;
  min-height: calc(100dvh - 140px);
}
.legal-page {
  width: 100%;
  max-width: 820px;
  padding: clamp(20px, 4vw, 48px);
  margin: 0 auto 28px;
}
.legal-page > p {
  margin: 0 0 14px;
}
.legal-page > .agreement-document {
  margin-top: 24px;
}
.legal-page h1 {
  margin: 0 0 14px;
  line-height: 1.25;
  font-size: clamp(1.45rem, 4vw, 2rem);
}
.agreement-actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 28px;
}
</style>
