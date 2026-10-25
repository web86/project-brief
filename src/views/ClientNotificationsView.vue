<script setup>
import { ref, computed, onUnmounted, watch } from 'vue'
import { usePersonalNotificationsStore } from '../stores/personalNotifications.js'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import PushControls from '../components/PushControls.vue'
import TelegramSettings from '../components/TelegramSettings.vue'
import NotificationChannel from '../components/NotificationChannel.vue'
import NotificationToggle from '../components/NotificationToggle.vue'
import LoadingButton from '../components/LoadingButton.vue'
const personal = usePersonalNotificationsStore()
const settings = ref(null),
  pending = ref(false),
  loading = ref(false),
  error = ref('')
const dirty = computed(
  () =>
    settings.value &&
    ['email', 'push', 'telegram'].some(
      (channel) => settings.value.preferences[channel] !== personal.settings?.preferences[channel],
    ),
)
let active = true
onUnmounted(() => {
  active = false
})
async function load() {
  const identity = personal.identity
  loading.value = true
  error.value = ''
  try {
    const result = await personal.loadSettings()
    if (active && identity === personal.identity && result)
      settings.value = { ...result, preferences: { ...result.preferences } }
  } catch (cause) {
    if (active && identity === personal.identity) error.value = formatApiError(cause)
  } finally {
    if (active && identity === personal.identity) loading.value = false
  }
}
async function save() {
  if (pending.value || !dirty.value) return
  const identity = personal.identity,
    key = identity?.key
  pending.value = true
  error.value = ''
  try {
    const result = await api.request('/api/client/notifications', {
      method: 'PATCH',
      body: { preferences: { ...settings.value.preferences } },
    })
    if (active && identity === personal.identity) {
      personal.adoptSettings(result, key)
      settings.value = { ...result, preferences: { ...result.preferences } }
      toast.success(() => t('notifications.saved'))
    }
  } catch (cause) {
    if (active && identity === personal.identity) error.value = formatApiError(cause)
  } finally {
    if (active && identity === personal.identity) pending.value = false
  }
}
watch(
  () => personal.identity?.key,
  () => {
    settings.value = null
    pending.value = false
    void load()
  },
  { immediate: true },
)
</script>
<template>
  <div class="personal-notifications client-notifications">
    <div class="page-heading">
      <h1>{{ t('centralNotifications.myNotifications') }}</h1>
      <p>{{ t('clientWorkspace.notificationHint') }}</p>
    </div>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <p v-if="loading" role="status">{{ t('nav.loading') }}</p>
    <button v-else-if="!settings" type="button" class="button secondary" @click="load">
      {{ t('notifications.retry') }}
    </button>
    <form v-if="settings" @submit.prevent="save">
      <TelegramSettings
        persona="client"
        :test-disabled="pending || dirty || !personal.settings?.preferences.telegram"
      >
        <template #channel-toggle="{ available }"
          ><NotificationToggle
            :checked="settings.preferences.telegram"
            :disabled="pending || !available"
            :label="t('centralNotifications.allowTelegram')"
            @change="settings.preferences.telegram = $event.target.checked"
          />
          <p v-if="!settings.preferences.telegram" class="small muted">
            {{ t('centralNotifications.telegramPaused') }}
          </p></template
        >
      </TelegramSettings>
      <NotificationChannel
        class="personal-email"
        title="Email"
        icon="mail"
        :description="t('notificationUi.emailDescription')"
        :status="
          t(
            !settings.email
              ? 'notificationUi.noEmail'
              : settings.preferences.email
                ? 'notificationUi.enabled'
                : 'centralNotifications.channelOff',
          )
        "
      >
        <NotificationToggle
          v-if="settings.email"
          :checked="settings.preferences.email"
          :disabled="pending"
          :label="t('centralNotifications.allowEmail')"
          @change="settings.preferences.email = $event.target.checked"
        />
        <p class="channel-address">{{ settings.email || t('notificationUi.emailContact') }}</p>
      </NotificationChannel>
      <NotificationChannel
        class="personal-push"
        title="Push"
        icon="bell"
        :description="t('notificationUi.pushDescription')"
        :status="
          t(
            settings.preferences.push
              ? 'notificationUi.enabled'
              : 'centralNotifications.channelOff',
          )
        "
      >
        <NotificationToggle
          :checked="settings.preferences.push"
          :disabled="pending"
          :label="t('centralNotifications.allowPush')"
          @change="settings.preferences.push = $event.target.checked"
        />
        <p v-if="!settings.preferences.push" class="small muted">
          {{ t('centralNotifications.pushPaused') }}
        </p>
        <PushControls
          :key="String(personal.settings?.preferences.push)"
          persona="client"
          inline
          :auto-enroll="!!personal.settings?.preferences.push"
        />
      </NotificationChannel>
      <div class="notification-save-bar">
        <p class="small muted" role="status">
          {{ t(dirty ? 'notificationUi.unsaved' : 'notificationUi.clientSaveHint') }}
        </p>
        <LoadingButton
          type="submit"
          :busy="pending"
          :disabled="pending || !dirty"
          class="button primary"
          >{{ t('notifications.save') }}</LoadingButton
        >
      </div>
    </form>
  </div>
</template>
