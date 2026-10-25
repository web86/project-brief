<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api/client.js'
import { t, formatApiError, intlLocale } from '../i18n/index.js'
import UserAvatar from '../components/UserAvatar.vue'
import LoadingButton from '../components/LoadingButton.vue'
const route = useRoute(),
  state = ref(null),
  inviting = ref(false),
  editing = ref(null),
  selected = ref([])
const email = ref(''),
  name = ref(''),
  projects = ref([]),
  busy = ref(false),
  error = ref(''),
  success = ref('')
const root = `/api/admin/projects/${route.params.uuid}/team`
async function load() {
  state.value = await api.request(root)
}
onMounted(async () => {
  try {
    await load()
  } catch (cause) {
    error.value = formatApiError(cause)
  }
})
async function action(path, method, body, key = 'team.saved') {
  if (busy.value) return
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    await api.request(root + path, { method, body })
    await load()
    success.value = t(key)
    inviting.value = false
    editing.value = null
  } catch (cause) {
    error.value =
      cause.code === 'account_mail_failed' ? t('account.mailFailed') : formatApiError(cause)
    await load().catch(() => {})
  } finally {
    busy.value = false
  }
}
async function invite() {
  await action(
    '/invitations',
    'POST',
    { email: email.value, name: name.value, projects: projects.value },
    'team.sent',
  )
  if (!error.value) {
    email.value = ''
    name.value = ''
    projects.value = []
  }
}
function edit(member) {
  editing.value = member.id
  selected.value = [...member.projects]
}
function date(value) {
  return value
    ? new Intl.DateTimeFormat(intlLocale(), { dateStyle: 'medium' }).format(new Date(value))
    : '—'
}
function revoke(invitation) {
  if (window.confirm(t('team.confirmRevoke'))) action('/invitations/' + invitation.id, 'DELETE')
}
function remove(member) {
  if (window.confirm(t('team.confirmRemove'))) action('/members/' + member.id, 'DELETE')
}
</script>
<template>
  <div class="team-page">
    <div class="page-heading team-heading">
      <div>
        <h2>{{ t('team.title') }}</h2>
        <p class="muted">{{ t('team.hint') }}</p>
      </div>
      <button class="button primary" :disabled="busy" @click="inviting = !inviting">
        {{ t('team.invite') }}
      </button>
    </div>
    <p v-if="error" role="alert" class="field-error">{{ error }}</p>
    <p v-if="success" role="status" class="account-success">{{ success }}</p>
    <template v-if="state">
      <form v-if="inviting" class="surface admin-form invitation-form" @submit.prevent="invite">
        <h3>{{ t('team.invite') }}</h3>
        <div class="field">
          <label for="invite-email">{{ t('team.email') }}</label
          ><input
            id="invite-email"
            v-model="email"
            type="email"
            autocomplete="email"
            maxlength="254"
            required
          />
        </div>
        <div class="field">
          <label for="invite-name">{{ t('team.name') }}</label
          ><input id="invite-name" v-model="name" autocomplete="name" maxlength="255" />
        </div>
        <fieldset class="project-choices">
          <legend>{{ t('team.projects') }}</legend>
          <label v-for="project in state.projects" :key="project.id"
            ><input v-model="projects" type="checkbox" :value="project.id" />{{
              project.name
            }}</label
          >
        </fieldset>
        <LoadingButton
          type="submit"
          class="button primary"
          :busy="busy"
          :disabled="busy || !projects.length"
          >{{ t('team.send') }}</LoadingButton
        >
      </form>
      <section class="surface team-list">
        <h3>{{ t('team.members') }}</h3>
        <article v-for="member in state.members" :key="member.id" class="team-member">
          <div class="team-identity">
            <UserAvatar :user="member.user" />
            <div>
              <strong>{{ member.user.name }}</strong>
              <p>{{ member.user.email }}</p>
              <span class="small muted"
                >{{ t('team.' + (member.role === 'owner' ? 'owner' : 'developer')) }} ·
                {{ t('team.active') }} · {{ t('team.joined') }}: {{ date(member.joinedAt) }}</span
              >
            </div>
          </div>
          <p class="small muted">
            {{
              member.role === 'owner'
                ? t('team.allProjects')
                : member.projects
                    .map((id) => state.projects.find((p) => p.id === id)?.name)
                    .filter(Boolean)
                    .join(', ') || t('team.noProjects')
            }}
          </p>
          <div v-if="member.role !== 'owner'" class="team-actions">
            <button class="button secondary" :disabled="busy" @click="edit(member)">
              {{ t('team.edit') }}</button
            ><button class="text-button danger" :disabled="busy" @click="remove(member)">
              {{ t('team.remove') }}
            </button>
          </div>
          <form
            v-if="editing === member.id"
            class="team-edit"
            @submit.prevent="action('/members/' + member.id, 'PATCH', { projects: selected })"
          >
            <fieldset class="project-choices">
              <legend>{{ t('team.projects') }}</legend>
              <label v-for="project in state.projects" :key="project.id"
                ><input v-model="selected" type="checkbox" :value="project.id" />{{
                  project.name
                }}</label
              >
            </fieldset>
            <div class="team-actions">
              <LoadingButton class="button primary" type="submit" :busy="busy" :disabled="busy">{{
                t('account.save')
              }}</LoadingButton
              ><button class="text-button" type="button" @click="editing = null">
                {{ t('account.cancel') }}
              </button>
            </div>
          </form>
        </article>
      </section>
      <section class="surface team-list">
        <h3>{{ t('team.invitations') }}</h3>
        <p v-if="!state.invitations.length" class="muted">{{ t('team.empty') }}</p>
        <article v-for="invitation in state.invitations" :key="invitation.id" class="team-member">
          <strong>{{ invitation.name || invitation.email }}</strong>
          <p v-if="invitation.name">{{ invitation.email }}</p>
          <p class="small muted">
            {{ t('team.' + invitation.status) }} · {{ date(invitation.expiresAt) }}
          </p>
          <p class="small muted">
            {{
              invitation.projects
                .map((id) => state.projects.find((p) => p.id === id)?.name)
                .filter(Boolean)
                .join(', ')
            }}
          </p>
          <div class="team-actions">
            <button
              class="button secondary"
              :disabled="busy"
              @click="
                action('/invitations/' + invitation.id + '/resend', 'POST', undefined, 'team.sent')
              "
            >
              {{ t('team.resend') }}</button
            ><button class="text-button danger" :disabled="busy" @click="revoke(invitation)">
              {{ t('team.revoke') }}
            </button>
          </div>
        </article>
      </section>
    </template>
  </div>
</template>
