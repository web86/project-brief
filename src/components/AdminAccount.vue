<script setup>
import { computed } from 'vue'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
const props = defineProps({ user: { type: Object, required: true }, busy: Boolean })
defineEmits(['logout'])
const initials = computed(() => {
  const words = (props.user.name || '').trim().split(/\s+/).filter(Boolean)
  return [words[0], words.length > 1 ? words.at(-1) : null]
    .filter(Boolean)
    .map((word) => Array.from(word)[0])
    .join('')
    .toLocaleUpperCase()
})
</script>
<template>
  <footer class="task-account">
    <span class="task-account-avatar" aria-hidden="true">
      {{ initials }}<AppIcon v-if="!initials" name="user" />
    </span>
    <div class="task-account-details">
      <strong>{{ user.name }}</strong>
      <span>{{ t(user.membershipRole === 'owner' ? 'nav.owner' : 'nav.admin') }}</span>
    </div>
    <button
      class="icon-button"
      :disabled="busy"
      :title="t('ui.signOut')"
      :aria-label="t('ui.signOut')"
      @click="$emit('logout')"
    >
      <AppIcon name="logout" :size="20" />
    </button>
  </footer>
</template>
