<script setup>
import { computed, ref, onMounted, onUnmounted, watch } from 'vue'
import { usePersonalNotificationsStore } from '../stores/personalNotifications.js'
import NotificationChannel from './NotificationChannel.vue'
import { api } from '../api/client.js'
import { t } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import LoadingButton from './LoadingButton.vue'
const props = defineProps({
  persona: { type: String, required: true },
  testDisabled: Boolean,
})
const personal = usePersonalNotificationsStore()
const status = computed(() => personal.telegram)
const refreshing = ref(false)
const busy = ref(false),
  link = ref(null),
  error = ref('')
const root = `/api/${props.persona}/telegram`
let active = true
async function refresh(force = true) {
  const identity = personal.identity
  refreshing.value = true
  try {
    await personal.loadTelegram(force)
    if (active && identity === personal.identity) {
      error.value = ''
      if (status.value?.connected) link.value = null
    }
  } catch {
    if (active && identity === personal.identity) error.value = 'telegram.loadError'
  } finally {
    if (active && identity === personal.identity) refreshing.value = false
  }
}
watch(
  () => personal.identity?.key,
  () => {
    link.value = null
    error.value = ''
    busy.value = false
    refreshing.value = false
    if (personal.identity) void refresh(false)
  },
)
async function run(action) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  const identity = personal.identity
  try {
    await action(() => active && identity === personal.identity)
  } catch (cause) {
    if (active && identity === personal.identity)
      error.value = `telegram.${['telegram_rate_limited', 'telegram_blocked', 'telegram_not_connected', 'telegram_unconfigured', 'telegram_unauthorized', 'telegram_chat_unavailable'].includes(cause.code) ? cause.code : 'deliveryError'}`
  } finally {
    if (active && identity === personal.identity) busy.value = false
  }
}
function connect() {
  return run(async (current) => {
    const result = await api.request(root + '/connect', { method: 'POST', body: {} })
    if (current()) link.value = result
  })
}
function disconnect() {
  return run(async (current) => {
    await api.request(root, { method: 'DELETE', body: {} })
    if (current()) {
      link.value = null
      await refresh()
    }
  })
}
function test() {
  return run(async (current) => {
    await api.request(root + '/test', { method: 'POST', body: {} })
    if (current()) toast.success(() => t('telegram.sent'))
  })
}
function focus() {
  if (!busy.value && !refreshing.value) void refresh()
}
onMounted(() => {
  refresh(false)
  globalThis.addEventListener?.('focus', focus)
})
onUnmounted(() => {
  active = false
  globalThis.removeEventListener?.('focus', focus)
})
</script>
<template>
  <NotificationChannel
    id="telegram-settings"
    class="telegram-settings personal-telegram"
    title="Telegram"
    :description="t('notificationUi.telegramDescription')"
    icon="send"
    featured
    :status="
      t(
        error && !status
          ? 'telegram.loadError'
          : status?.available === false
            ? 'telegram.unavailable'
            : status?.connected
              ? 'telegram.connected'
              : status?.available
                ? 'telegram.disconnected'
                : 'nav.loading',
      )
    "
    :aria-busy="busy || refreshing"
    tabindex="-1"
  >
    <slot name="channel-toggle" :available="status?.available === true" />
    <p class="muted channel-help">{{ t('telegram.personalHint') }}</p>
    <p v-if="error" class="field-error" role="alert">{{ t(error) }}</p>
    <div class="notification-test-actions">
      <LoadingButton
        v-if="status?.available"
        type="button"
        :busy="busy"
        :disabled="busy || refreshing"
        class="button primary"
        @click="connect"
        >{{ t(status.connected ? 'telegram.reconnect' : 'telegram.connect') }}</LoadingButton
      >
      <LoadingButton
        v-if="status?.connected"
        type="button"
        :busy="busy"
        :disabled="busy || refreshing || testDisabled || !status?.available"
        class="button secondary"
        @click="test"
        >{{ t('telegram.test') }}</LoadingButton
      >
      <LoadingButton
        v-if="status?.connected"
        type="button"
        :busy="busy"
        :disabled="busy || refreshing"
        class="button secondary"
        @click="disconnect"
        >{{ t('telegram.disconnect') }}</LoadingButton
      >
      <button type="button" class="text-button" :disabled="busy || refreshing" @click="refresh()">
        {{ t('telegram.refresh') }}
      </button>
    </div>
    <div v-if="link" class="telegram-link" role="status">
      <p>{{ t('telegram.linkHint') }}</p>
      <p class="small muted">{{ t('notificationUi.telegramSteps') }}</p>
      <a
        class="button primary"
        :href="link.url"
        target="_blank"
        rel="noopener noreferrer"
        referrerpolicy="no-referrer"
        >{{ t('telegram.openBot') }}</a
      >
    </div>
  </NotificationChannel>
</template>
