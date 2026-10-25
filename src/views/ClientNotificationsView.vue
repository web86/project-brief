<script setup>
import { ref, computed, onUnmounted, watch } from 'vue'
import { usePersonalNotificationsStore } from '../stores/personalNotifications.js'
import { t, formatApiError } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import PushControls from '../components/PushControls.vue'
import TelegramSettings from '../components/TelegramSettings.vue'
import NotificationChannel from '../components/NotificationChannel.vue'
import NotificationToggle from '../components/NotificationToggle.vue'
const personal = usePersonalNotificationsStore()
const settings = computed(() => personal.settings),
  pending = computed(() => Object.keys(personal.savingPreferences).length > 0),
  loading = ref(false),
  error = ref('')
let active = true
onUnmounted(() => {
  active = false
})
async function load() {
  const identity = personal.identity
  loading.value = true
  error.value = ''
  try {
    await personal.loadSettings()
  } catch (cause) {
    if (active && identity === personal.identity) error.value = formatApiError(cause)
  } finally {
    if (active && identity === personal.identity) loading.value = false
  }
}
async function toggle(channel, event) {
  const enabled = event.target.checked
  event.target.checked = settings.value.preferences[channel]
  if (personal.savingPreferences[channel]) return
  const identity = personal.identity
  error.value = ''
  try {
    const saved = await personal.savePreference(channel, enabled)
    if (saved && active && identity === personal.identity)
      toast.success(() => t('notifications.saved'))
  } catch (cause) {
    if (active && identity === personal.identity) error.value = formatApiError(cause)
  }
}

watch(
  () => personal.identity?.key,
  () => {
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
    <div v-if="settings" :aria-busy="pending">
      <TelegramSettings persona="client" :test-disabled="pending || !settings.preferences.telegram">
        <template #channel-toggle="{ available }"
          ><NotificationToggle
            :checked="settings.preferences.telegram"
            :disabled="!!personal.savingPreferences.telegram || !available"
            :label="t('centralNotifications.allowTelegram')"
            @change="toggle('telegram', $event)"
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
          :disabled="!!personal.savingPreferences.email"
          :label="t('centralNotifications.allowEmail')"
          @change="toggle('email', $event)"
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
          :disabled="!!personal.savingPreferences.push"
          :label="t('centralNotifications.allowPush')"
          @change="toggle('push', $event)"
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
      <p v-if="pending" class="small muted" role="status">{{ t('notificationUi.saving') }}</p>
    </div>
  </div>
</template>
