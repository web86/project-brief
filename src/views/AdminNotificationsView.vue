<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { usePersonalNotificationsStore } from '../stores/personalNotifications.js'
import NotificationChannel from '../components/NotificationChannel.vue'
import NotificationToggle from '../components/NotificationToggle.vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import AccountTabs from '../components/AccountTabs.vue'
import PushControls from '../components/PushControls.vue'
import TelegramSettings from '../components/TelegramSettings.vue'
import LoadingButton from '../components/LoadingButton.vue'
const route = useRoute(),
  router = useRouter()
const personal = usePersonalNotificationsStore()
const settings = computed(() => personal.settings)
const loading = ref(false)
const email = ref(''),
  busy = ref(false),
  error = ref('')
const pushState = ref('loading')
let active = true
onUnmounted(() => {
  active = false
})
const root = '/api/admin/notifications'
function message(cause) {
  if (cause.code === 'device_not_subscribed') return t('notifications.subscribeFirst')
  if (cause.code?.startsWith('push_')) return t('notifications.pushError')
  return [
    'smtp_unconfigured',
    'email_delivery_failed',
    'email_rate_limited',
    'email_verification_invalid',
    'channel_disabled',
  ].includes(cause.code)
    ? t('centralNotifications.errors.' + cause.code)
    : formatApiError(cause)
}
async function load() {
  error.value = ''
  loading.value = true
  const identity = personal.identity
  try {
    const state = await personal.loadSettings()
    if (active && identity === personal.identity && state) {
      email.value = state.pendingEmail || state.email
    }
  } catch (cause) {
    if (active && identity === personal.identity) error.value = message(cause)
  } finally {
    if (active && identity === personal.identity) loading.value = false
  }
}
async function run(action, success) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  const identity = personal.identity,
    key = identity?.key
  const current = () => active && identity === personal.identity
  try {
    await action(key, current)
    if (current() && success) toast.success(() => t(success))
  } catch (cause) {
    if (current()) error.value = message(cause)
  } finally {
    if (current()) busy.value = false
  }
}
function toggle(channel, event) {
  const enabled = event.target.checked
  event.target.checked = settings.value.preferences[channel]
  return run(() => personal.savePreference(channel, enabled), 'notifications.saved')
}
function saveEmail() {
  return run(async (key, current) => {
    const result = await api.request(root, { method: 'PATCH', body: { email: email.value } })
    if (current()) {
      personal.adoptSettings(result, key)
      email.value = result.pendingEmail || result.email
      toast.success(() =>
        t(result.pendingEmail ? 'centralNotifications.verificationSent' : 'notifications.saved'),
      )
    }
  })
}
function cancelEmail() {
  return run(async (key, current) => {
    const result = await api.request(root + '/email/pending', { method: 'DELETE', body: {} })
    if (current()) {
      personal.adoptSettings(result, key)
      email.value = result.email
    }
  })
}
function test(channel) {
  return run(async (key, current) => {
    let endpoint = null
    if (channel === 'push') {
      const registration = await globalThis.navigator?.serviceWorker?.getRegistration('/')
      endpoint = (await registration?.pushManager?.getSubscription())?.endpoint || null
      if (!endpoint) {
        toast.info(() => t('notifications.subscribeFirst'))
        return
      }
    }
    await api.request(root + '/test', {
      method: 'POST',
      body: { channel, ...(endpoint ? { endpoint } : {}) },
    })
    if (current())
      toast.success(() =>
        t(channel === 'email' ? 'centralNotifications.emailSent' : 'notifications.pushSent'),
      )
  })
}
let verifying = false
async function verifyFragment() {
  const token = /^#verify=([a-f0-9]{64})$/.exec(route.hash)?.[1]
  if (!token || busy.value || verifying) return
  verifying = true
  try {
    await router.replace({ path: '/admin/settings/notifications', hash: '' })
    if (!active) return
    if (!settings.value) await load()
    if (active && settings.value)
      await run(async (key, current) => {
        const result = await api.request(root + '/email/verify', {
          method: 'POST',
          body: { token },
        })
        if (current()) {
          personal.adoptSettings(result, key)
          email.value = result.email
        }
      }, 'centralNotifications.emailVerified')
  } finally {
    verifying = false
  }
}
watch([() => route.hash, busy], () => {
  if (!busy.value) void verifyFragment()
})
watch(
  () => personal.identity?.key,
  () => {
    email.value = ''
    busy.value = false
    error.value = ''
    void load()
  },
)
onMounted(async () => {
  if (/^#verify=[a-f0-9]{64}$/.test(route.hash)) await verifyFragment()
  else await load()
})
</script>
<template>
  <AccountTabs />
  <div class="personal-notifications">
    <div class="page-heading">
      <h1>{{ t('centralNotifications.myNotifications') }}</h1>
      <p>{{ t('centralNotifications.personalHint') }}</p>
    </div>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <p v-if="loading" role="status">{{ t('nav.loading') }}</p>
    <button v-else-if="!settings" class="button secondary" :disabled="busy" @click="load">
      {{ t('notifications.retry') }}
    </button>
    <template v-if="settings">
      <TelegramSettings persona="admin" :test-disabled="busy || !settings.preferences.telegram"
        ><template #channel-toggle="{ available }"
          ><NotificationToggle
            :checked="settings.preferences.telegram"
            :disabled="busy || !available"
            :label="t('centralNotifications.allowTelegram')"
            @change="toggle('telegram', $event)"
          />
          <p v-if="!settings.preferences.telegram" class="small muted">
            {{ t('centralNotifications.telegramPaused') }}
          </p></template
        ></TelegramSettings
      >
      <NotificationChannel
        class="personal-email"
        title="Email"
        icon="mail"
        :description="t('notificationUi.emailDescription')"
        :status="
          t(
            !settings.emailAvailable
              ? 'centralNotifications.emailUnavailable'
              : settings.preferences.email
                ? 'notificationUi.enabled'
                : 'centralNotifications.channelOff',
          )
        "
      >
        <NotificationToggle
          :checked="settings.preferences.email"
          :disabled="busy"
          :label="t('centralNotifications.allowEmail')"
          @change="toggle('email', $event)"
        />
        <p class="muted">{{ t('centralNotifications.currentEmail') }}: {{ settings.email }}</p>
        <form @submit.prevent="saveEmail">
          <div class="field">
            <label for="personal-notification-email">{{
              t('centralNotifications.receiveEmail')
            }}</label
            ><input
              id="personal-notification-email"
              v-model="email"
              type="email"
              maxlength="254"
              required
              :disabled="busy"
              autocomplete="email"
            />
          </div>
          <p class="small muted">{{ t('centralNotifications.emailVerificationHint') }}</p>
          <div class="notification-test-actions">
            <LoadingButton type="submit" class="button secondary" :busy="busy" :disabled="busy">{{
              t(
                email.toLowerCase() === settings.accountEmail.toLowerCase()
                  ? 'centralNotifications.unifyAccount'
                  : 'centralNotifications.verifyEmail',
              )
            }}</LoadingButton
            ><LoadingButton
              type="button"
              class="button secondary"
              :busy="busy"
              :disabled="busy || !settings.preferences.email || !settings.emailAvailable"
              @click="test('email')"
              >{{ t('notifications.testEmail') }}</LoadingButton
            >
          </div>
        </form>
        <div v-if="settings.pendingEmail" class="notification-pending-email" role="status">
          <p>{{ t('centralNotifications.pendingEmail', { email: settings.pendingEmail }) }}</p>
          <p class="small muted">{{ t('centralNotifications.expiresHint') }}</p>
          <button class="text-button" :disabled="busy" @click="cancelEmail">
            {{ t('centralNotifications.cancelChange') }}
          </button>
        </div>
        <div v-if="settings.legacyEmails.length" class="notification-legacy-emails">
          <h3>{{ t('centralNotifications.savedAddresses') }}</h3>
          <p class="small muted">
            {{
              t(
                settings.emailUnified
                  ? 'centralNotifications.addressesArchived'
                  : 'centralNotifications.addressConflict',
              )
            }}
          </p>
          <ul>
            <li v-for="item in settings.legacyEmails" :key="item.projectId">
              <span
                >{{ item.projectName }} · {{ item.workspaceName }}<br /><strong>{{
                  item.email
                }}</strong
                ><br /><small class="muted">{{
                  t(
                    item.active
                      ? 'centralNotifications.legacyActive'
                      : 'centralNotifications.legacyArchived',
                  )
                }}</small></span
              ><button class="text-button" :disabled="busy" @click="email = item.email">
                {{ t('centralNotifications.chooseAddress') }}
              </button>
            </li>
          </ul>
        </div>
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
          :disabled="busy"
          :label="t('centralNotifications.allowPush')"
          @change="toggle('push', $event)"
        />
        <p v-if="!settings.preferences.push" class="muted">
          {{ t('centralNotifications.pushPaused') }}
        </p>
        <PushControls
          :key="String(settings.preferences.push)"
          persona="admin"
          inline
          :auto-enroll="settings.preferences.push"
          @state="pushState = $event"
        />
        <LoadingButton
          type="button"
          class="button secondary"
          :busy="busy"
          :disabled="busy || !settings.preferences.push || pushState !== 'enabled'"
          @click="test('push')"
          >{{ t('notifications.testPush') }}</LoadingButton
        >
      </NotificationChannel>
    </template>
  </div>
</template>
