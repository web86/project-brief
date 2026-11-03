<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { demo, STATIC_DEMO } from '../services/demo.js'
import { api, API_MODE } from '../api/client.js'
import { t } from '../i18n/index.js'
import ProviderIcon from './ProviderIcon.vue'
const props = defineProps({ readiness: Object })
const emit = defineEmits(['readiness'])
const providers = ref({}),
  busy = ref(''),
  error = ref('')
const available = computed(() =>
  ['google', 'yandex', 'github'].filter((key) => providers.value[key]),
)
let current = true
onBeforeUnmount(() => {
  current = false
})
onMounted(async () => {
  if (STATIC_DEMO || demo.readOnly || !API_MODE) return
  try {
    const state = props.readiness || (await api.request('/api/onboarding/readiness'))
    if (!current) return
    providers.value = state.providers || {}
    emit('readiness', state)
  } catch {
    error.value = t('onboarding.socialHint')
  }
})
async function start(provider) {
  busy.value = provider
  error.value = ''
  try {
    const result = await api.request('/api/auth/' + provider + '/start', {
      method: 'POST',
      body: {},
    })
    const url = new URL(result.url)
    if (
      !['accounts.google.com', 'oauth.yandex.ru', 'github.com'].includes(url.hostname) ||
      url.protocol !== 'https:'
    )
      throw new Error('invalid_url')
    window.location.assign(url.href)
  } catch {
    error.value = t('onboarding.oauthFailed')
    busy.value = ''
  }
}
</script>
<template>
  <div v-if="!STATIC_DEMO && !demo.readOnly && (available.length || error)" class="social-sign-in">
    <p class="muted social-divider">{{ t('onboarding.alternate') }}</p>
    <div class="social-sign-in-buttons">
      <button
        v-for="provider in available"
        :key="provider"
        type="button"
        class="button secondary"
        :disabled="!!busy || !providers[provider]"
        @click="start(provider)"
      >
        <ProviderIcon :name="provider" />
        {{ t('onboarding.' + provider) }}
      </button>
    </div>
    <p v-if="error" role="alert" class="field-error">{{ error }}</p>
  </div>
</template>
<style scoped>
.social-sign-in {
  display: grid;
  gap: 12px;
  margin-top: 4px;
}
.social-sign-in-buttons {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(112px, 1fr));
  gap: 10px;
}
.social-sign-in-buttons .button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  padding: 10px 8px;
  min-height: 44px;
  min-width: 0;
}
.social-sign-in-buttons :deep(svg) {
  width: 20px;
  height: 20px;
  flex-shrink: 0;
}
small {
  font-size: 0.75rem;
  font-weight: 400;
}
@media (max-width: 520px) {
  .social-sign-in-buttons {
    grid-template-columns: 1fr;
  }
}
</style>

<style scoped>
.social-divider {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 0;
  font-size: 0.85rem;
}
.social-divider::before,
.social-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--border);
}
</style>
