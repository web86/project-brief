<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import PushControls from '../components/PushControls.vue'
import TelegramSettings from '../components/TelegramSettings.vue'
import LoadingButton from '../components/LoadingButton.vue'
const settings = ref(null),
  pending = ref(false),
  error = ref('')
let active = true
onUnmounted(() => {
  active = false
})
async function load() {
  try {
    const result = await api.request('/api/client/notifications')
    if (active) {
      error.value = ''
      settings.value = result
    }
  } catch (cause) {
    if (active) error.value = formatApiError(cause)
  }
}
async function save() {
  if (pending.value) return
  pending.value = true
  error.value = ''
  try {
    const result = await api.request('/api/client/notifications', {
      method: 'PATCH',
      body: { preferences: { ...settings.value.preferences } },
    })
    if (active) {
      settings.value = result
      toast.success(() => t('notifications.saved'))
    }
  } catch (cause) {
    if (active) error.value = formatApiError(cause)
  } finally {
    pending.value = false
  }
}
onMounted(load)
</script>
<template>
  <div class="surface client-notifications">
    <h1>{{ t('nav.notifications') }}</h1>
    <p class="muted">{{ t('clientWorkspace.notificationHint') }}</p>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <button v-if="!settings" class="text-button" @click="load">
      {{ t('notifications.retry') }}
    </button>
    <form v-if="settings" @submit.prevent="save">
      <fieldset class="notification-group" :disabled="pending">
        <legend>{{ t('clientWorkspace.personalPreferences') }}</legend>
        <label
          v-for="channel in ['email', 'push', 'telegram'].filter(
            (channel) => channel !== 'email' || settings.email,
          )"
          :key="channel"
          class="personal-channel-option"
        >
          <input v-model="settings.preferences[channel]" type="checkbox" />
          {{ { email: 'Email', push: 'Push', telegram: 'Telegram' }[channel] }}
        </label>
        <p v-if="settings.email" class="muted">Email: {{ settings.email }}</p>
      </fieldset>
      <LoadingButton type="submit" :busy="pending" :disabled="pending" class="button primary">{{
        t('notifications.save')
      }}</LoadingButton>
    </form>
    <section class="notification-channel">
      <h2>Push</h2>
      <PushControls persona="client" inline />
    </section>
    <TelegramSettings persona="client" />
  </div>
</template>
