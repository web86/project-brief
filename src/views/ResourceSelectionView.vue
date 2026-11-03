<script setup>
import { ref, onMounted } from 'vue'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { api } from '../api/client.js'
import { t, locale, formatApiError } from '../i18n/index.js'
import ResourceSelectionPanel from '../components/ResourceSelectionPanel.vue'
const nav = useAdminNavigationStore(),
  versions = ref([]),
  target = ref(''),
  error = ref(''),
  page = ref(0),
  hasMore = ref(false)
async function loadVersions() {
  try {
    const r = await api.request('/api/admin/resource-selection/versions?page=' + (page.value + 1))
    versions.value.push(...r.data)
    page.value = r.meta.page
    hasMore.value = r.meta.page < r.meta.lastPage
  } catch (e) {
    error.value = formatApiError(e)
  }
}
onMounted(loadVersions)
</script>
<template>
  <main class="owner-resource-preview">
    <h1>{{ t('preparation.title') }}</h1>
    <p>{{ t('preparation.intro') }}</p>
    <p v-if="error" role="alert">{{ error }}</p>
    <label
      >{{ t('preparation.target')
      }}<select v-model="target">
        <option value="">{{ t('preparation.choose') }}</option>
        <option v-for="v in versions" :key="v.id" :value="v.id">
          {{ v.translations[locale]?.name || v.translations.en.name }} · v{{ v.number }}
        </option>
      </select></label
    ><button v-if="hasMore" class="text-button" @click="loadVersions">
      {{ t('planManagement.next') }}
    </button>
    <p v-if="!versions.length">{{ t('preparation.missingTarget') }}</p>
    <ResourceSelectionPanel :workspace-id="nav.workspace?.id" :target-id="target" />
  </main>
</template>
<style scoped>
.owner-resource-preview {
  max-width: 1100px;
  padding: 24px;
  margin: 0 auto;
  min-width: 0;
}
.owner-resource-preview > p {
  margin-block: 16px;
}
.owner-resource-preview > label {
  display: grid;
  gap: 8px;
  max-width: 100%;
}
.owner-resource-preview select {
  padding: 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface);
  color: var(--text);
  max-width: 100%;
  font: inherit;
}
</style>
