<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client.js'
import { t, setManualLocale, locale, LANGUAGE_CATALOG } from '../i18n/index.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import AccountTabs from '../components/AccountTabs.vue'
import UserAvatar from '../components/UserAvatar.vue'
import LoadingButton from '../components/LoadingButton.vue'
const route = useRoute(),
  router = useRouter(),
  nav = useAdminNavigationStore()
const security = computed(() => route.path.endsWith('/security'))
const state = ref(null),
  name = ref(''),
  language = ref(locale.value),
  source = ref('initials'),
  file = ref(null)
const current = ref(''),
  password = ref(''),
  confirmation = ref(''),
  email = ref(''),
  emailPassword = ref('')
const busy = ref(false),
  error = ref(''),
  success = ref('')
let emailToken = /^#email=([a-f0-9]{64})$/.exec(route.hash)?.[1]
if (emailToken) router.replace({ path: route.path, hash: '' })
function apply(result) {
  state.value = result
  name.value = result.user.name
  language.value = result.user.preferredLocale || locale.value
  source.value = result.user.avatarSource || 'initials'
  nav.user = { ...nav.user, ...result.user }
}
function message(cause) {
  return cause.status === 410
    ? t('account.invalidLink')
    : cause.code === 'account_mail_failed'
      ? t('account.mailFailed')
      : cause.status === 422
        ? t('account.fieldError')
        : cause.status === 401
          ? t('account.wrongAccount')
          : t('ui.weCouldNotCompleteThisActionPleaseTry')
}
async function action(run, key) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    await run()
    success.value = t(key)
  } catch (cause) {
    error.value = message(cause)
  } finally {
    busy.value = false
  }
}
onMounted(async () => {
  try {
    apply(await api.request('/api/admin/account'))
    if (emailToken)
      await action(async () => {
        apply(
          await api.request('/api/admin/account/email/confirm', {
            method: 'POST',
            body: { token: emailToken },
          }),
        )
      }, 'account.emailConfirmed')
  } catch (cause) {
    error.value = message(cause)
  } finally {
    emailToken = null
  }
})
function save() {
  return action(async () => {
    apply(
      await api.request('/api/admin/account', {
        method: 'PATCH',
        body: { name: name.value, preferredLocale: language.value },
      }),
    )
    setManualLocale(language.value)
  }, 'account.saved')
}
function saveAvatar() {
  return action(async () => {
    const body = new FormData()
    body.append('source', source.value)
    if (file.value) body.append('avatar', file.value)
    apply(await api.request('/api/admin/account/avatar', { method: 'POST', body }))
    file.value = null
  }, 'account.saved')
}
function changeEmail() {
  return action(async () => {
    await api.request('/api/admin/account/email', {
      method: 'POST',
      body: { email: email.value, current_password: emailPassword.value },
    })
    emailPassword.value = ''
    apply(await api.request('/api/admin/account'))
  }, 'account.emailSent')
}
function cancelEmail() {
  return action(async () => {
    apply(await api.request('/api/admin/account/email', { method: 'DELETE' }))
  }, 'account.saved')
}
function changePassword() {
  return action(async () => {
    await api.request('/api/admin/account/password', {
      method: 'POST',
      body: {
        current_password: current.value,
        password: password.value,
        password_confirmation: confirmation.value,
      },
    })
    api.resetCsrf()
    current.value = ''
    password.value = ''
    confirmation.value = ''
  }, 'account.passwordChanged')
}
</script>
<template>
  <div class="account-page">
    <h1>{{ t('account.title') }}</h1>
    <AccountTabs />
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <p v-if="success" class="account-success" role="status">{{ success }}</p>
    <template v-if="state">
      <template v-if="!security">
        <form class="surface admin-form" @submit.prevent="save">
          <div class="account-heading">
            <UserAvatar :user="state.user" />
            <h2>{{ t('account.profile') }}</h2>
          </div>
          <div class="field">
            <label for="account-name">{{ t('account.name') }}</label
            ><input id="account-name" v-model="name" autocomplete="name" maxlength="255" required />
          </div>
          <div class="field">
            <label for="account-language">{{ t('account.language') }}</label
            ><select id="account-language" v-model="language">
              <option
                v-for="item in LANGUAGE_CATALOG"
                :key="item.code"
                :value="item.code"
                :lang="item.code"
              >
                {{ item.name }}
              </option>
            </select>
          </div>
          <LoadingButton class="button primary" type="submit" :busy="busy" :disabled="busy">{{
            t('account.save')
          }}</LoadingButton>
        </form>
        <form class="surface admin-form" @submit.prevent="saveAvatar">
          <h2>{{ t('account.avatar') }}</h2>
          <div class="field">
            <label for="avatar-source">{{ t('account.avatar') }}</label
            ><select id="avatar-source" v-model="source">
              <option value="initials">{{ t('account.initials') }}</option>
              <option value="gravatar">{{ t('account.gravatar') }}</option>
              <option value="upload">{{ t('account.upload') }}</option>
            </select>
          </div>
          <p v-if="source === 'gravatar'" class="muted small">{{ t('account.gravatarHelp') }}</p>
          <div v-if="source === 'upload'" class="field">
            <label for="avatar-file">{{ t('account.upload') }}</label
            ><input
              id="avatar-file"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              required
              @change="file = $event.target.files[0]"
            />
            <p class="small muted">{{ t('account.avatarHelp') }}</p>
          </div>
          <LoadingButton class="button secondary" type="submit" :busy="busy" :disabled="busy">{{
            t('account.save')
          }}</LoadingButton>
        </form>
      </template>
      <template v-else>
        <form class="surface admin-form" @submit.prevent="changeEmail">
          <h2>{{ t('account.email') }}</h2>
          <p>{{ state.user.email }}</p>
          <p class="muted small">{{ t('account.emailHelp') }}</p>
          <p v-if="state.pendingEmail" role="status">
            {{ t('account.pendingEmail', { email: state.pendingEmail }) }}
            <button class="text-button" type="button" :disabled="busy" @click="cancelEmail">
              {{ t('account.cancel') }}
            </button>
          </p>
          <div class="field">
            <label for="new-email">{{ t('account.newEmail') }}</label
            ><input
              id="new-email"
              v-model="email"
              type="email"
              autocomplete="email"
              maxlength="254"
              required
            />
          </div>
          <div class="field">
            <label for="email-password">{{ t('account.currentPassword') }}</label
            ><input
              id="email-password"
              v-model="emailPassword"
              type="password"
              autocomplete="current-password"
              maxlength="1024"
              required
            />
          </div>
          <LoadingButton class="button primary" type="submit" :busy="busy" :disabled="busy">{{
            t('account.sendConfirmation')
          }}</LoadingButton>
        </form>
        <form class="surface admin-form" @submit.prevent="changePassword">
          <h2>{{ t('account.changePassword') }}</h2>
          <p class="small muted">{{ t('account.passwordHelp') }}</p>
          <div class="field">
            <label for="current-password">{{ t('account.currentPassword') }}</label
            ><input
              id="current-password"
              v-model="current"
              type="password"
              autocomplete="current-password"
              maxlength="1024"
              required
            />
          </div>
          <div class="field">
            <label for="new-password">{{ t('account.newPassword') }}</label
            ><input
              id="new-password"
              v-model="password"
              type="password"
              autocomplete="new-password"
              minlength="9"
              maxlength="1024"
              required
            />
          </div>
          <div class="field">
            <label for="confirm-password">{{ t('account.confirmPassword') }}</label
            ><input
              id="confirm-password"
              v-model="confirmation"
              type="password"
              autocomplete="new-password"
              minlength="9"
              maxlength="1024"
              required
            />
          </div>
          <LoadingButton class="button primary" type="submit" :busy="busy" :disabled="busy">{{
            t('account.changePassword')
          }}</LoadingButton>
        </form>
      </template>
    </template>
  </div>
</template>
