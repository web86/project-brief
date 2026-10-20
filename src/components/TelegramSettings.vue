<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { api } from '../api/client.js'
import { t } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import LoadingButton from './LoadingButton.vue'
const props = defineProps({
  persona: { type: String, required: true },
  testDisabled: Boolean,
  headingLevel: { type: Number, default: 3 },
})
const status = ref(null),
  busy = ref(false),
  link = ref(null),
  error = ref('')
const root = `/api/${props.persona}/telegram`
let active = true
async function refresh() {
  try {
    const result = await api.request(root)
    if (active) {
      error.value = ''
      status.value = result
      if (result.connected) link.value = null
    }
  } catch {
    if (active) error.value = 'telegram.loadError'
  }
}
async function run(action) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await action()
  } catch (cause) {
    if (active)
      error.value = `telegram.${['telegram_rate_limited', 'telegram_blocked', 'telegram_not_connected', 'telegram_unconfigured', 'telegram_unauthorized', 'telegram_chat_unavailable'].includes(cause.code) ? cause.code : 'deliveryError'}`
  } finally {
    busy.value = false
  }
}
function connect() {
  return run(async () => {
    const result = await api.request(root + '/connect', { method: 'POST', body: {} })
    if (active) link.value = result
  })
}
function disconnect() {
  return run(async () => {
    await api.request(root, { method: 'DELETE', body: {} })
    if (active) {
      link.value = null
      await refresh()
    }
  })
}
function test() {
  return run(async () => {
    await api.request(root + '/test', { method: 'POST', body: {} })
    if (active) toast.success(() => t('telegram.sent'))
  })
}
function focus() {
  if (!busy.value) void refresh()
}
onMounted(() => {
  refresh()
  globalThis.addEventListener?.('focus', focus)
})
onUnmounted(() => {
  active = false
  globalThis.removeEventListener?.('focus', focus)
})
</script>
<template>
  <section class="notification-channel telegram-settings" :aria-busy="busy">
    <component :is="headingLevel === 2 ? 'h2' : 'h3'">Telegram</component>
    <slot name="channel-toggle" />
    <p role="status">
      {{
        t(
          status?.available === false
            ? 'telegram.unavailable'
            : status?.connected
              ? 'telegram.connected'
              : status?.available
                ? 'telegram.disconnected'
                : status
                  ? 'telegram.unavailable'
                  : 'nav.loading',
        )
      }}
    </p>
    <p class="muted">{{ t('telegram.personalHint') }}</p>
    <p v-if="error" class="field-error" role="alert">{{ t(error) }}</p>
    <div class="notification-test-actions">
      <LoadingButton
        v-if="status?.available"
        :busy="busy"
        :disabled="busy"
        class="button secondary"
        @click="connect"
        >{{ t(status.connected ? 'telegram.reconnect' : 'telegram.connect') }}</LoadingButton
      >
      <LoadingButton
        v-if="status?.connected"
        :busy="busy"
        :disabled="busy"
        class="button secondary"
        @click="disconnect"
        >{{ t('telegram.disconnect') }}</LoadingButton
      >
      <LoadingButton
        v-if="status?.connected"
        :busy="busy"
        :disabled="busy || testDisabled || !status?.available"
        class="button secondary"
        @click="test"
        >{{ t('telegram.test') }}</LoadingButton
      >
      <button type="button" class="text-button" :disabled="busy" @click="refresh">
        {{ t('telegram.refresh') }}
      </button>
    </div>
    <div v-if="link" class="telegram-link">
      <p>{{ t('telegram.linkHint') }}</p>
      <a
        class="button primary"
        :href="link.url"
        target="_blank"
        rel="noopener noreferrer"
        referrerpolicy="no-referrer"
        >{{ t('telegram.openBot') }}</a
      >
    </div>
  </section>
</template>
