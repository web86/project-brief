<script setup>
import { ref, onMounted, computed, nextTick } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { documentLanguages } from '../utils/agreementLanguages.js'
import AgreementEditor from '../components/AgreementEditor.vue'
import LoadingButton from '../components/LoadingButton.vue'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
const locales = ['ru', 'en', 'tr', 'zh-CN']
const state = ref(null),
  language = ref('ru'),
  busy = ref(false),
  error = ref(null),
  success = ref(false),
  again = ref(false),
  publicUrl = ref(null),
  original = ref('')
const feedback = ref(null),
  templates = ref({}),
  editorRevision = ref(0)
function changeLanguage(lang, event) {
  toggleLanguage(lang, event.target.checked)
  event.target.checked = state.value.draft.enabledLocales.includes(lang)
}
function toggleLanguage(lang, checked) {
  const config = state.value.draft
  if (!checked && (config.defaultLocale === lang || config.enabledLocales.length === 1)) {
    window.alert(t('agreement.chooseDefaultFirst'))
    return
  }
  config.enabledLocales = checked
    ? [...new Set([...config.enabledLocales, lang])]
    : config.enabledLocales.filter((item) => item !== lang)
}
function useTemplate() {
  const text = state.value.draft.texts[language.value]
  if (
    text.some((block) => block.content.some((span) => span.text.trim())) &&
    !window.confirm(t('agreement.replaceTemplate'))
  )
    return
  state.value.draft.texts[language.value] = JSON.parse(
    JSON.stringify(templates.value[language.value]),
  )
  editorRevision.value++
}
function languageKey(event, index) {
  const key = event.key
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(key)) return
  event.preventDefault()
  const next = key === 'Home' ? 0 : key === 'End' ? 3 : (index + (key === 'ArrowRight' ? 1 : 3)) % 4
  language.value = locales[next]
  document.getElementById('agreement-tab-' + language.value)?.focus()
}
const dirty = computed(() => state.value && JSON.stringify(state.value) !== original.value)
onBeforeRouteLeave(() => !dirty.value || window.confirm(t('agreement.unsaved')))
async function load() {
  try {
    const result = await api.request('/api/admin/settings/agreement')
    adopt(result)
  } catch (e) {
    error.value = e
  }
}
function adopt(result) {
  state.value = result.data
  for (const lang of locales) state.value.draft.texts[lang] ||= []
  const config = documentLanguages(state.value.draft)
  state.value.draft.enabledLocales = config.enabled
  state.value.draft.defaultLocale = config.default
  templates.value = result.templates || templates.value
  publicUrl.value = result.publicUrl
  original.value = JSON.stringify(result.data)
  again.value = false
}
async function save(publish = false) {
  if (busy.value) return
  busy.value = true
  error.value = null
  success.value = false
  try {
    adopt(
      await api.request('/api/admin/settings/agreement', {
        method: 'PUT',
        body: {
          draft: state.value.draft,
          enabled: state.value.enabled,
          baseRevision: state.value.published?.id || null,
          publish,
          requireAgain: publish && again.value,
        },
      }),
    )
    const nav = useAdminNavigationStore()
    if (nav.workspace?.branding)
      nav.workspace = {
        ...nav.workspace,
        branding: { ...nav.workspace.branding, agreementUrl: publicUrl.value },
      }
    success.value = true
  } catch (e) {
    error.value = e
  } finally {
    busy.value = false
    await nextTick()
    feedback.value?.focus()
  }
}
onMounted(load)
</script>
<template>
  <section class="agreement-settings">
    <div class="page-heading">
      <h1>{{ t('agreement.settings') }}</h1>
      <RouterLink v-if="publicUrl" :to="publicUrl" target="_blank">{{
        t('agreement.publicPage')
      }}</RouterLink>
    </div>
    <p class="muted">{{ t('agreement.settingsHint') }}</p>
    <p v-if="error" ref="feedback" tabindex="-1" class="form-error" role="alert">
      {{ error.status === 409 ? t('agreement.conflict') : formatApiError(error) }}
      <span v-for="(messages, key) in error.errors" :key="key">{{
        Array.isArray(messages) ? messages.join(' ') : messages
      }}</span>
    </p>
    <p v-if="success" role="status">{{ t('agreement.saved') }}</p>
    <form v-if="state" class="surface agreement-settings-form" @submit.prevent="save(false)">
      <label class="agreement-option"
        ><input v-model="state.enabled" type="checkbox" :disabled="busy" />{{
          t('agreement.require')
        }}</label
      >
      <label class="field agreement-date"
        >{{ t('agreement.version')
        }}<input v-model="state.draft.date" type="date" required :disabled="busy"
      /></label>
      <div class="agreement-languages" role="tablist" :aria-label="t('agreement.languages')">
        <div
          v-for="(lang, index) in locales"
          :key="lang"
          class="agreement-language-option"
          :class="{ 'is-disabled': !state.draft.enabledLocales.includes(lang) }"
        >
          <div>
            <button
              :id="'agreement-tab-' + lang"
              class="button secondary"
              type="button"
              role="tab"
              :aria-selected="language === lang"
              :tabindex="language === lang ? 0 : -1"
              aria-controls="agreement-language-panel"
              @keydown="languageKey($event, index)"
              @click="language = lang"
            >
              {{ lang.toUpperCase() }}
            </button>
          </div>
          <div class="agreement-language-controls">
            <label
              ><input
                type="checkbox"
                :aria-label="lang.toUpperCase() + ': ' + t('agreement.languageEnabled')"
                :checked="state.draft.enabledLocales.includes(lang)"
                :disabled="busy"
                @change="changeLanguage(lang, $event)"
              />{{ t('agreement.languageEnabled') }}</label
            >
            <label
              ><input
                v-model="state.draft.defaultLocale"
                type="radio"
                :value="lang"
                name="agreement-default-language"
                :aria-label="lang.toUpperCase() + ': ' + t('agreement.defaultLanguage')"
                :disabled="busy || !state.draft.enabledLocales.includes(lang)"
              />{{ t('agreement.defaultLanguage') }}</label
            >
          </div>
        </div>
      </div>
      <button
        type="button"
        class="text-button agreement-template"
        :disabled="busy"
        @click="useTemplate"
      >
        {{ t('agreement.fillTemplate') }}
      </button>
      <div
        id="agreement-language-panel"
        role="tabpanel"
        :aria-labelledby="'agreement-tab-' + language"
      >
        <AgreementEditor
          :key="language + editorRevision"
          v-model="state.draft.texts[language]"
          :disabled="busy"
        />
      </div>
      <label class="agreement-option"
        ><input v-model="again" type="checkbox" :disabled="busy" />{{
          t('agreement.requireAgain')
        }}</label
      >
      <p class="small muted">{{ t('agreement.publishHint') }}</p>
      <div class="agreement-settings-actions">
        <LoadingButton class="button secondary" type="submit" :busy="busy" :disabled="busy">{{
          t('account.save')
        }}</LoadingButton
        ><button
          class="button primary"
          type="button"
          :disabled="busy || !state.draft.date"
          @click="save(true)"
        >
          {{ t('agreement.publish') }}
        </button>
      </div>
    </form>
  </section>
</template>
<style scoped>
.agreement-settings {
  max-width: 900px;
}
.agreement-settings .page-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.agreement-settings > .muted {
  margin: 12px 0 20px;
}
.agreement-settings .page-heading h1 {
  margin: 0;
}
.agreement-settings [role='status'],
.agreement-settings [role='alert'] {
  margin-bottom: 16px;
}
.agreement-settings-form .field {
  margin: 0;
}
.agreement-settings-form {
  display: grid;
  gap: 20px;
  padding: 24px;
}
.agreement-settings-form [role='tabpanel'] {
  min-width: 0;
}
.agreement-option {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}
.agreement-option input {
  width: 18px;
  height: 18px;
  min-height: 18px;
  padding: 0;
  flex: 0 0 18px;
  margin-top: 2px;
}
.agreement-date {
  max-width: 220px;
}
.agreement-language,
.agreement-settings-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.agreement-settings-actions {
  justify-content: flex-end;
}
.agreement-languages [aria-selected='true'] {
  outline: 2px solid var(--link);
}
.agreement-languages {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}
.agreement-language-option {
  min-width: 0;
}
.agreement-language-option.is-disabled button {
  color: var(--muted);
  border-style: dashed;
}
.agreement-language-controls {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 12px;
  margin-top: 10px;
  font-size: 0.75rem;
}
.agreement-language-controls label {
  display: flex;
  gap: 6px;
  align-items: center;
}
.agreement-language-controls input {
  width: 16px;
  height: 16px;
  min-height: 16px;
  padding: 0;
  margin: 0;
}
.agreement-template {
  justify-self: start;
}
@media (max-width: 600px) {
  .agreement-languages {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .agreement-settings-form {
    padding: 16px;
  }
}
</style>
