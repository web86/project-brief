<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import PushControls from '../components/PushControls.vue'
import TelegramSettings from '../components/TelegramSettings.vue'
import LoadingButton from '../components/LoadingButton.vue'
const route = useRoute(),
  router = useRouter()
const settings = ref(null),
  email = ref(''),
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
  try {
    const state = await api.request(root)
    if (active) {
      settings.value = state
      email.value = state.pendingEmail || state.email
    }
  } catch (cause) {
    if (active) error.value = message(cause)
  }
}
async function run(action, success) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await action()
    if (active && success) toast.success(() => t(success))
  } catch (cause) {
    if (active) error.value = message(cause)
  } finally {
    if (active) busy.value = false
  }
}
function toggle(channel, event) {
  const previous = settings.value.preferences[channel],
    enabled = event.target.checked
  return run(async () => {
    settings.value.preferences[channel] = enabled
    try {
      const result = await api.request(root, {
        method: 'PATCH',
        body: { preferences: { ...settings.value.preferences } },
      })
      if (active) settings.value = result
    } catch (cause) {
      if (active) settings.value.preferences[channel] = previous
      throw cause
    }
  }, 'notifications.saved')
}
function saveEmail() {
  return run(async () => {
    const result = await api.request(root, { method: 'PATCH', body: { email: email.value } })
    if (active) {
      settings.value = result
      email.value = result.pendingEmail || result.email
      toast.success(() =>
        t(result.pendingEmail ? 'centralNotifications.verificationSent' : 'notifications.saved'),
      )
    }
  })
}
function cancelEmail() {
  return run(async () => {
    const result = await api.request(root + '/email/pending', { method: 'DELETE', body: {} })
    if (active) {
      settings.value = result
      email.value = result.email
    }
  })
}
function test(channel) {
  return run(async () => {
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
    if (active)
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
      await run(async () => {
        const result = await api.request(root + '/email/verify', {
          method: 'POST',
          body: { token },
        })
        if (active) {
          settings.value = result
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
onMounted(async () => {
  if (/^#verify=[a-f0-9]{64}$/.test(route.hash)) await verifyFragment()
  else await load()
})
</script>
<template>
  <div class="personal-notifications">
    <div class="page-heading">
      <h1>{{ t('centralNotifications.myNotifications') }}</h1>
      <p>{{ t('centralNotifications.personalHint') }}</p>
    </div>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <button v-if="!settings" class="button secondary" :disabled="busy" @click="load">
      {{ t('notifications.retry') }}
    </button>
    <template v-if="settings">
      <section class="surface notification-channel personal-email">
        <h2>Email</h2>
        <label class="personal-channel-option"
          ><input
            type="checkbox"
            :checked="settings.preferences.email"
            :disabled="busy"
            @change="toggle('email', $event)"
          />{{ t('centralNotifications.allowEmail') }}</label
        >
        <p role="status">
          {{
            t(
              settings.preferences.email
                ? settings.emailAvailable
                  ? 'centralNotifications.emailReady'
                  : 'centralNotifications.emailUnavailable'
                : 'centralNotifications.channelOff',
            )
          }}
        </p>
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
      </section>
      <section class="surface notification-channel personal-push">
        <h2>Push</h2>
        <label class="personal-channel-option"
          ><input
            type="checkbox"
            :checked="settings.preferences.push"
            :disabled="busy"
            @change="toggle('push', $event)"
          />{{ t('centralNotifications.allowPush') }}</label
        >
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
      </section>
      <div class="surface personal-telegram">
        <TelegramSettings
          persona="admin"
          :heading-level="2"
          :test-disabled="busy || !settings.preferences.telegram"
          ><template #channel-toggle
            ><p class="muted">@BriefWeb86Bot</p>
            <label class="personal-channel-option"
              ><input
                type="checkbox"
                :checked="settings.preferences.telegram"
                :disabled="busy"
                @change="toggle('telegram', $event)"
              />{{ t('centralNotifications.allowTelegram') }}</label
            >
            <p v-if="!settings.preferences.telegram" class="muted">
              {{ t('centralNotifications.telegramPaused') }}
            </p></template
          ></TelegramSettings
        >
      </div>
    </template>
  </div>
</template>
