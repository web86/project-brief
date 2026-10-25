<script setup>
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
import UserAvatar from './UserAvatar.vue'
defineProps({ user: { type: Object, required: true }, busy: Boolean })
defineEmits(['logout', 'navigate'])
</script>
<template>
  <footer class="task-account">
    <RouterLink to="/admin/account" class="account-profile-link" @click="$emit('navigate')"
      ><UserAvatar :user="user" />
      <div class="task-account-details">
        <strong>{{ user.name }}</strong
        ><span>{{ t(user.membershipRole === 'owner' ? 'nav.owner' : 'team.developer') }}</span>
      </div></RouterLink
    >
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
