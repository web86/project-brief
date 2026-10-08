<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { t } from '../i18n/index.js'
import { api } from '../api/client.js'
import { createNotificationSettings, notificationEvents } from '../services/notificationSettings.js'
import { toast } from '../services/toast.js'
import LoadingButton from './LoadingButton.vue'
import LoadingOverlay from './LoadingOverlay.vue'
const props = defineProps({ projectId: { type: String, required: true } })
const client = createNotificationSettings(api.request, props.projectId)
const settings = ref(null)
const loading = ref(true)
const saving = ref(false)
const testing = ref(null)
const validation = ref({})
let active = true
onUnmounted(() => {
  active = false
})
const error = ref('')
async function load() {
  loading.value = true
  error.value = ''
  try {
    settings.value = await client.load()
  } catch {
    error.value = 'notifications.loadError'
  } finally {
    loading.value = false
  }
}
async function save() {
  if (saving.value) return
  saving.value = true
  validation.value = {}
  error.value = ''
  try {
    settings.value = await client.save(settings.value)
    validation.value = {}
    if (active) toast.success(() => t('notifications.saved'))
  } catch (cause) {
    if (cause.status === 422 && Object.keys(cause.errors || {}).length)
      validation.value = cause.errors
    else if (active) toast.error(() => t('notifications.saveError'))
  } finally {
    saving.value = false
  }
}
async function test(channel) {
  if (testing.value) return
  testing.value = channel
  error.value = ''
  try {
    let endpoint = null
    if (channel === 'push') {
      const registration = await globalThis.navigator?.serviceWorker?.getRegistration('/')
      endpoint = (await registration?.pushManager.getSubscription())?.endpoint || null
      if (!endpoint) {
        if (active) toast.info(() => t('notifications.subscribeFirst'))
        return
      }
    }
    await client.test(channel, endpoint)
    if (active)
      toast.success(() =>
        t(channel === 'email' ? 'notifications.emailSent' : 'notifications.pushSent'),
      )
  } catch (cause) {
    const key =
      cause.code === 'device_not_subscribed'
        ? 'notifications.subscribeFirst'
        : channel === 'email'
          ? 'notifications.emailError'
          : 'notifications.pushError'
    if (active) toast.error(() => t(key))
  } finally {
    testing.value = null
  }
}
onMounted(load)
</script>
<template>
  <details class="surface notification-settings">
    <summary>{{ t('notifications.title') }}</summary>
    <div class="notification-settings-body">
      <LoadingOverlay :active="loading" block />
      <p v-if="error" class="field-error" role="alert">{{ t(error) }}</p>
      <button v-if="!settings && !loading" type="button" class="button secondary" @click="load">
        {{ t('notifications.retry') }}
      </button>
      <form v-if="settings" @submit.prevent="save">
        <div class="field">
          <label for="notification-email">{{ t('notifications.adminEmail') }}</label>
          <input
            id="notification-email"
            type="email"
            v-model="settings.notificationEmail"
            :placeholder="settings.fallbackEmail || ''"
            maxlength="255"
            :aria-invalid="!!validation.notificationEmail"
            :aria-describedby="
              validation.notificationEmail ? 'notification-email-error' : undefined
            "
          />
          <p
            v-if="validation.notificationEmail"
            id="notification-email-error"
            class="field-error"
            role="alert"
          >
            {{ t('flow.fieldError') }}
          </p>
          <p class="muted field-hint">{{ t('notifications.emailFallback') }}</p>
        </div>
        <p v-if="validation.events" class="field-error" role="alert">{{ t('flow.fieldError') }}</p>
        <fieldset v-for="group in ['client', 'admin']" :key="group" class="notification-group">
          <legend>
            {{
              t(group === 'client' ? 'notifications.notifyAdmin' : 'notifications.notifyClients')
            }}
          </legend>
          <div class="notification-columns" aria-hidden="true">
            <span></span><span>Email</span><span>Push</span>
          </div>
          <div
            v-for="event in notificationEvents.filter((event) => event.startsWith(group + '.'))"
            :key="event"
            class="notification-columns"
          >
            <span :id="`event-${event}`">{{ t(`notifications.events.${event}`) }}</span>
            <label
              v-for="channel in ['email', 'push']"
              :key="channel"
              class="notification-checkbox"
            >
              <input
                type="checkbox"
                v-model="settings.events[event][channel]"
                :aria-label="`${t(`notifications.events.${event}`)} — ${channel === 'email' ? 'Email' : 'Push'}`"
              />
              <span
                v-if="validation[`events.${event}.${channel}`]"
                class="field-error"
                role="alert"
                >{{ t('flow.fieldError') }}</span
              >
            </label>
          </div>
        </fieldset>
        <LoadingButton type="submit" :busy="saving" :disabled="saving" class="button primary">{{
          t('notifications.save')
        }}</LoadingButton>
      </form>
      <div v-if="settings" class="notification-test-actions">
        <LoadingButton
          :busy="testing === 'email'"
          :disabled="!!testing"
          class="button secondary"
          @click="test('email')"
          >{{ t('notifications.testEmail') }}</LoadingButton
        >
        <LoadingButton
          :busy="testing === 'push'"
          :disabled="!!testing"
          class="button secondary"
          @click="test('push')"
          >{{ t('notifications.testPush') }}</LoadingButton
        >
      </div>
    </div>
  </details>
</template>
