<script setup>
import { computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { useProjectStore } from '../stores/project.js'
import { createInvitationFlow } from '../services/invitationFlow.js'
import LoadingButton from '../components/LoadingButton.vue'
const route = useRoute(),
  router = useRouter()
const token = /^#token=([a-f0-9]{64})$/.exec(route.hash)?.[1] || ''
const {
  state,
  register,
  name,
  password,
  confirmation,
  error,
  busy,
  mismatch,
  signInRequired,
  load,
  chooseRegistration,
  accept,
  logout,
} = createInvitationFlow({
  token,
  request: api.request,
  resetCsrf: api.resetCsrf,
  onSignedOut() {
    useAdminNavigationStore().clear()
    useProjectStore().admin = null
  },
  async onAccepted() {
    useAdminNavigationStore().clear()
    await router.replace('/admin')
  },
})
const errorMessage = computed(() =>
  error.value
    ? error.value.status === 410 || !token
      ? t('account.invalidLink')
      : formatApiError(error.value)
    : '',
)
onMounted(async () => {
  await router.replace({ path: route.path, hash: '' })
  await load()
})
</script>
<template>
  <div class="admin-login invitation-page" :aria-busy="busy">
    <h1>{{ t('account.invitation') }}</h1>
    <p v-if="errorMessage" role="alert" class="field-error">{{ errorMessage }}</p>
    <button
      v-if="error && error.status !== 410 && token"
      class="text-button"
      :disabled="busy"
      @click="load"
    >
      {{ t('ui.tryAgain') }}
    </button>
    <form v-if="state" class="surface admin-form" @submit.prevent="accept">
      <h2>{{ t('account.invitedTo', { name: state.workspace }) }}</h2>
      <p v-if="!mismatch">
        {{ t('invitation.invitedEmail') }} <strong>{{ state.email }}</strong>
      </p>
      <ul class="invitation-projects">
        <li v-for="project in state.projects" :key="project">{{ project }}</li>
      </ul>
      <section v-if="mismatch" class="invitation-account-notice" role="status">
        <h3>{{ t('invitation.otherAccount') }}</h3>
        <p>
          {{ t('invitation.invitedEmail') }} <strong>{{ state.email }}</strong>
        </p>
        <p>
          {{ t('invitation.currentEmail') }} <strong>{{ state.authenticatedEmail }}</strong>
        </p>
        <p>{{ t('invitation.switchHelp') }}</p>
        <LoadingButton
          type="button"
          class="button primary"
          :busy="busy"
          :disabled="busy"
          @click="logout"
          >{{ t('invitation.switchAccount') }}</LoadingButton
        >
      </section>
      <template v-else>
        <template v-if="!state.authenticated">
          <p class="small muted">{{ t('invitation.entryHelp') }}</p>
          <div class="team-actions invitation-entry-actions">
            <button
              type="button"
              class="button secondary"
              :aria-pressed="register"
              :disabled="busy"
              @click="chooseRegistration(true)"
            >
              {{ t('account.register') }}
            </button>
            <button
              type="button"
              class="button secondary"
              :aria-pressed="!register"
              :disabled="busy"
              @click="chooseRegistration(false)"
            >
              {{ t('invitation.existingAccount') }}
            </button>
          </div>
          <p v-if="signInRequired" class="invitation-account-notice small" role="status">
            {{ t('invitation.signInRequired') }}
          </p>
          <div v-if="register" class="field">
            <label for="invited-name">{{ t('account.name') }}</label
            ><input id="invited-name" v-model="name" autocomplete="name" maxlength="255" required />
          </div>
          <div class="field">
            <label for="invited-password">{{
              t(register ? 'account.newPassword' : 'ui.password')
            }}</label
            ><input
              id="invited-password"
              v-model="password"
              type="password"
              :autocomplete="register ? 'new-password' : 'current-password'"
              :minlength="register ? 9 : undefined"
              maxlength="1024"
              required
            />
          </div>
          <div v-if="register" class="field">
            <label for="invited-confirmation">{{ t('account.confirmPassword') }}</label
            ><input
              id="invited-confirmation"
              v-model="confirmation"
              type="password"
              autocomplete="new-password"
              minlength="9"
              maxlength="1024"
              required
            />
          </div>
        </template>
        <p v-else class="small muted">{{ t('invitation.ready') }}</p>
        <LoadingButton type="submit" class="button primary" :busy="busy" :disabled="busy">{{
          t(
            state.authenticated
              ? 'account.accept'
              : register
                ? 'invitation.registerAndAccept'
                : 'invitation.signInAndAccept',
          )
        }}</LoadingButton>
        <button
          v-if="state.authenticated"
          type="button"
          class="text-button"
          :disabled="busy"
          @click="logout"
        >
          {{ t('account.logout') }}
        </button>
        <RouterLink v-else to="/admin/forgot-password">{{ t('account.forgot') }}</RouterLink>
      </template>
    </form>
  </div>
</template>
