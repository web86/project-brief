<script setup>
import { computed, watch } from 'vue'
import { useProjectStore } from '../stores/project.js'
import { usePersonalNotificationsStore } from '../stores/personalNotifications.js'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
const notifications = usePersonalNotificationsStore(),
  project = useProjectStore()
const destination = computed(() => ({
  path:
    notifications.identity?.persona === 'admin'
      ? '/admin/settings/notifications'
      : `${project.projectPath}/notifications`,
  hash: '#telegram-settings',
}))
watch(
  () => notifications.identity?.key,
  async () => {
    if (notifications.dismissed) return
    const identity = notifications.identity
    try {
      // Unsupported servers need no additional preferences request for the prompt.
      const status = await notifications.loadTelegram()
      if (identity === notifications.identity && !notifications.dismissed && status?.available)
        await notifications.loadSettings()
    } catch {
      // The settings page provides explicit retry; unknown state hides the prompt.
    }
  },
  { immediate: true },
)
</script>
<template>
  <aside
    v-if="notifications.needsTelegram"
    class="telegram-prompt"
    :aria-label="t('notificationUi.promptTitle')"
  >
    <span class="channel-icon"><AppIcon name="bell" :size="23" /></span>
    <div class="telegram-prompt-copy">
      <h2>{{ t('notificationUi.promptTitle') }}</h2>
      <p>{{ t('notificationUi.promptDescription') }}</p>
    </div>
    <RouterLink class="button primary" :to="destination" @click="notifications.dismiss()">{{
      t('notificationUi.configureTelegram')
    }}</RouterLink>
    <button
      type="button"
      class="icon-button telegram-prompt-close"
      :aria-label="t('notificationUi.dismissPrompt')"
      @click="notifications.dismiss()"
    >
      <AppIcon name="close" :size="18" />
    </button>
  </aside>
</template>
