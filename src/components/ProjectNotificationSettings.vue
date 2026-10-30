<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { t, formatApiError } from '../i18n/index.js'
import { api } from '../api/client.js'
import { createNotificationSettings, notificationEvents } from '../services/notificationSettings.js'
import { toast } from '../services/toast.js'
import LoadingButton from './LoadingButton.vue'
import LoadingOverlay from './LoadingOverlay.vue'
const props = defineProps({ projectId: { type: String, required: true }, initialSettings: Object })
const client = createNotificationSettings(api.request, props.projectId)
function normalize(value) {
  const result = JSON.parse(JSON.stringify(value))
  result.deliveryMode ||= 'legacy'
  result.recipientOptions ||= { team: [], clients: [] }
  const savedRules = result.eventRules || {}
  result.eventRules = Object.fromEntries(
    notificationEvents.map((event) => [
      event,
      savedRules[event] || {
        enabled: Object.values(result.events?.[event] || { email: true, push: true }).some(Boolean),
        recipients: { mode: 'all', ids: [] },
      },
    ]),
  )
  result.unavailableRecipients = {}
  for (const event of notificationEvents) {
    const recipients = result.eventRules[event].recipients
    const allowed = new Set(
      result.recipientOptions[
        event.startsWith('client.') || event === 'team.message_created' ? 'team' : 'clients'
      ].map((person) => person.id),
    )
    const previous = recipients.ids.length
    recipients.ids = recipients.ids.filter((id) => allowed.has(id))
    result.unavailableRecipients[event] = previous - recipients.ids.length
  }
  return result
}
const settings = ref(props.initialSettings ? normalize(props.initialSettings) : null),
  loading = ref(!props.initialSettings),
  saving = ref(false),
  error = ref(''),
  confirmed = ref(false)
let active = true
onUnmounted(() => {
  active = false
})
async function load() {
  loading.value = true
  error.value = ''
  try {
    const result = await client.load()
    if (active) settings.value = normalize(result)
  } catch (cause) {
    if (active) error.value = formatApiError(cause)
  } finally {
    if (active) loading.value = false
  }
}
async function save() {
  if (saving.value || (settings.value.deliveryMode === 'legacy' && !confirmed.value)) return
  saving.value = true
  error.value = ''
  try {
    const result = await client.save({
      eventRules: settings.value.eventRules,
      confirmMigration: confirmed.value,
    })
    if (active) {
      settings.value = normalize(result)
      toast.success(() => t('notifications.saved'))
    }
  } catch (cause) {
    if (active)
      error.value =
        cause.status === 422 ? t('centralNotifications.recipientError') : formatApiError(cause)
  } finally {
    if (active) saving.value = false
  }
}
function candidates(event) {
  return settings.value.recipientOptions[
    event.startsWith('client.') || event === 'team.message_created' ? 'team' : 'clients'
  ]
}
function legacyChannels(event) {
  return (
    ['email', 'push', 'telegram']
      .filter((channel) => settings.value.events?.[event]?.[channel])
      .map((channel) => ({ email: 'Email', push: 'Push', telegram: 'Telegram' })[channel])
      .join(' · ') || t('centralNotifications.none')
  )
}
onMounted(() => {
  if (!props.initialSettings) load()
})
</script>
<template>
  <section class="surface notification-settings project-event-settings">
    <h2>{{ t('notifications.title') }}</h2>
    <p class="muted">
      {{ t('centralNotifications.projectHint') }}
      <RouterLink to="/admin/settings/notifications">{{
        t('centralNotifications.myNotifications')
      }}</RouterLink>
    </p>
    <LoadingOverlay :active="loading" block />
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <button v-if="!settings && !loading" class="button secondary" @click="load">
      {{ t('notifications.retry') }}
    </button>
    <form v-if="settings" @submit.prevent="save">
      <div
        v-if="settings.deliveryMode === 'legacy'"
        class="notification-migration-note"
        role="note"
      >
        <strong>{{ t('centralNotifications.legacyTitle') }}</strong>
        <p>{{ t('centralNotifications.legacyExplanation') }}</p>
        <label
          ><input v-model="confirmed" type="checkbox" :disabled="saving" />{{
            t('centralNotifications.confirmMigration')
          }}</label
        >
      </div>
      <fieldset
        v-for="group in ['client', 'admin']"
        :key="group"
        class="notification-group"
        :disabled="saving"
      >
        <legend>
          {{
            t(
              group === 'client'
                ? 'centralNotifications.clientActions'
                : 'centralNotifications.teamActions',
            )
          }}
        </legend>
        <article
          v-for="event in notificationEvents.filter((event) => event.startsWith(group + '.'))"
          :key="event"
          class="notification-event-row"
        >
          <div class="notification-event-label">
            <label :for="`active-${event}`"
              ><input
                :id="`active-${event}`"
                type="checkbox"
                v-model="settings.eventRules[event].enabled"
              />{{ t(`notifications.events.${event}`) }}</label
            >
            <p v-if="settings.deliveryMode === 'legacy'" class="small muted">
              {{ t('centralNotifications.currentChannels') }}: {{ legacyChannels(event) }}
            </p>
          </div>
          <div class="notification-event-recipients">
            <label :for="`recipients-${event}`">{{ t('centralNotifications.recipients') }}</label>
            <select
              :id="`recipients-${event}`"
              v-model="settings.eventRules[event].recipients.mode"
              :disabled="!settings.eventRules[event].enabled"
            >
              <option value="all">{{ t('centralNotifications.allRecipients') }}</option>
              <option value="selected">{{ t('centralNotifications.selectedRecipients') }}</option>
            </select>
            <p v-if="settings.unavailableRecipients[event]" class="small muted">
              {{ t('centralNotifications.unavailableRecipients') }}
            </p>
            <div
              v-if="settings.eventRules[event].recipients.mode === 'selected'"
              class="notification-recipient-list"
            >
              <label v-for="person in candidates(event)" :key="person.id"
                ><input
                  v-model="settings.eventRules[event].recipients.ids"
                  type="checkbox"
                  :value="person.id"
                  :disabled="!settings.eventRules[event].enabled"
                />{{ person.name }}</label
              >
              <p v-if="!candidates(event).length" class="small muted">
                {{ t('centralNotifications.noRecipients') }}
              </p>
              <p v-else-if="!settings.eventRules[event].recipients.ids.length" class="small muted">
                {{ t('centralNotifications.noneSelected') }}
              </p>
            </div>
          </div>
        </article>
      </fieldset>
      <LoadingButton
        type="submit"
        class="button primary"
        :busy="saving"
        :disabled="saving || (settings.deliveryMode === 'legacy' && !confirmed)"
        >{{
          t(
            settings.deliveryMode === 'legacy'
              ? 'centralNotifications.confirmAndSave'
              : 'notifications.save',
          )
        }}</LoadingButton
      >
    </form>
  </section>
</template>
