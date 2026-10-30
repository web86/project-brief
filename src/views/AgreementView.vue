<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t, locale, formatApiError } from '../i18n/index.js'
import { appDestination } from '../services/session.js'
import { documentLanguages, agreementLanguage } from '../utils/agreementLanguages.js'
import { brandTokens } from '../services/branding.js'
import { setDocumentBranding } from '../services/workspaceAppearance.js'
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
const selectedLanguage = ref(null)
const availableLanguages = computed(() => documentLanguages(state.value?.document).enabled)
const documentLanguage = computed(() =>
  agreementLanguage(state.value?.document, selectedLanguage.value || locale.value),
)
const languageNames = { ru: 'Русский', en: 'English', tr: 'Türkçe', 'zh-CN': '简体中文' }
const printDocument = () => window.print()
let revision = 0
onBeforeUnmount(() => revision++)
async function load() {
  const current = ++revision
  setDocumentBranding(null, false)
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
    setDocumentBranding(result.workspace.branding)
  } catch (e) {
    if (current === revision) {
      error.value = e
      setDocumentBranding(null)
    }
  }
}
async function accept() {
  if (busy.value) return
  busy.value = true
  error.value = null
  try {
    await api.request('/api/admin/agreement/accept', {
      method: 'POST',
      body: { revision: state.value.document.id, locale: documentLanguage.value },
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
    selectedLanguage.value = ['ru', 'en', 'tr', 'zh-CN'].includes(route.query.lang)
      ? route.query.lang
      : null
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
        <label class="agreement-document-language"
          >{{ t('agreement.documentLanguage') }}
          <select
            :value="documentLanguage"
            @change="selectedLanguage = $event.target.value"
            :aria-label="t('agreement.documentLanguage')"
          >
            <option v-for="lang in availableLanguages" :key="lang" :value="lang">
              {{ languageNames[lang] }}
            </option>
          </select>
        </label>
        <p v-if="documentLanguage !== locale" class="small muted" role="status">
          {{ t('agreement.languageFallback', { language: languageNames[documentLanguage] }) }}
        </p>
        <AgreementDocument :blocks="state.document.texts[documentLanguage]" />
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
.agreement-document-language {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin: 1rem 0 1.5rem;
}
.agreement-document-language select {
  width: auto;
  min-width: 140px;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
}
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
.agreement-document-language {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin: 18px 0;
}
.agreement-document-language select {
  width: auto;
  max-width: 100%;
}
.agreement-actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 28px;
}
</style>
