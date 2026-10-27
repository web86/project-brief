<script setup>
import { isSecurityCancellation } from '../services/stepUp.js'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { toast } from '../services/toast.js'
import { api } from '../api/client.js'
import { t, formatApiError, intlLocale } from '../i18n/index.js'
import { defaultProjectPermissions } from '../services/projectPermissions.js'
import NotificationToggle from '../components/NotificationToggle.vue'
import AppIcon from '../components/AppIcon.vue'
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
const createProjects = ref(false)
const permissionProject = ref(''),
  permissionValues = ref({})
const permissionKeys = Object.keys(defaultProjectPermissions)
const assignedProjects = computed(
  () => state.value?.projects.filter((project) => selected.value.includes(project.id)) || [],
)
watch(
  selected,
  (ids) => {
    for (const id of ids) permissionValues.value[id] ||= { ...defaultProjectPermissions }
    if (!ids.includes(permissionProject.value)) permissionProject.value = ids[0] || ''
  },
  { deep: true },
)
function saveMember(member) {
  const projectPermissions = Object.fromEntries(
    selected.value.map((id) => [id, permissionValues.value[id]]),
  )
  return action('/members/' + member.id, 'PATCH', {
    projects: selected.value,
    projectPermissions,
    canCreateProjects: createProjects.value,
  })
}
const root = `/api/admin/projects/${route.params.uuid}/team`
async function load() {
  state.value = await api.request(root)
}
onMounted(async () => {
  try {
    await load()
  } catch (cause) {
    if (isSecurityCancellation(cause)) return
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
    toast.success(() => t(key))
    inviting.value = false
    editing.value = null
    return true
  } catch (cause) {
    if (isSecurityCancellation(cause)) return
    error.value =
      cause.code === 'account_mail_failed' ? t('account.mailFailed') : formatApiError(cause)
    if (cause.code === 'security_retry_required') toast.info(() => formatApiError(cause))
    else toast.error(() => formatApiError(cause))
    await load().catch(() => {})
  } finally {
    busy.value = false
  }
}
async function invite() {
  const saved = await action(
    '/invitations',
    'POST',
    { email: email.value, name: name.value, projects: projects.value },
    'team.sent',
  )
  if (saved) {
    email.value = ''
    name.value = ''
    projects.value = []
  }
}
function edit(member) {
  editing.value = member.id
  createProjects.value = member.canCreateProjects === true
  permissionValues.value = Object.fromEntries(
    member.projects.map((id) => [
      id,
      { ...defaultProjectPermissions, ...member.projectPermissions?.[id] },
    ]),
  )
  selected.value = [...member.projects]
  permissionProject.value = member.projects.includes(route.params.uuid)
    ? route.params.uuid
    : member.projects[0] || ''
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
        <AppIcon name="plus" :size="18" />{{ t('team.invite') }}
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
              <strong class="person-name">{{ member.user.name }}</strong>
              <p class="person-meta muted">
                <AppIcon name="mail" :size="16" /><span>{{ member.user.email }}</span>
              </p>
              <p v-if="member.security" class="small muted">
                {{ t('security.passkeys') }}:
                {{ t(member.security.passkey ? 'security.configured' : 'security.notConfigured') }}
                · {{ t('security.authenticator') }}:
                {{ t(member.security.totp ? 'security.configured' : 'security.notConfigured') }}
              </p>
              <div class="person-badges">
                <span class="person-role"
                  ><AppIcon name="users" :size="14" />{{
                    t('team.' + (member.role === 'owner' ? 'owner' : 'developer'))
                  }}</span
                ><span class="client-state"
                  ><AppIcon name="check" :size="13" />{{ t('team.active') }}</span
                >
              </div>
              <p class="small muted person-meta">
                <AppIcon name="clock" :size="15" /><span
                  >{{ t('team.joined') }}: {{ date(member.joinedAt) }}</span
                >
              </p>
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
              <AppIcon name="edit" :size="16" />{{ t('team.edit') }}</button
            ><button class="text-button danger" :disabled="busy" @click="remove(member)">
              <AppIcon name="trash" :size="16" />{{ t('team.remove') }}
            </button>
          </div>
          <form v-if="editing === member.id" class="team-edit" @submit.prevent="saveMember(member)">
            <fieldset class="project-choices">
              <legend>{{ t('team.projects') }}</legend>
              <label v-for="project in state.projects" :key="project.id"
                ><input v-model="selected" type="checkbox" :value="project.id" />{{
                  project.name
                }}</label
              >
            </fieldset>
            <div class="member-creation-permission">
              <NotificationToggle
                :checked="createProjects"
                :disabled="busy"
                :label="t('permissions.createProjects')"
                @change="createProjects = $event.target.checked"
              />
              <p class="small muted">{{ t('permissions.createProjectsHint') }}</p>
            </div>
            <fieldset v-if="assignedProjects.length" class="member-permissions">
              <legend>{{ t('permissions.title') }}</legend>
              <div class="field">
                <label :for="'permission-project-' + member.id">{{
                  t('permissions.project')
                }}</label>
                <select :id="'permission-project-' + member.id" v-model="permissionProject">
                  <option v-for="project in assignedProjects" :key="project.id" :value="project.id">
                    {{ project.name }}
                  </option>
                </select>
              </div>
              <p class="small muted">{{ t('permissions.hint') }}</p>
              <template v-if="permissionValues[permissionProject]">
                <div v-for="key in permissionKeys" :key="key" class="member-permission">
                  <NotificationToggle
                    :checked="permissionValues[permissionProject][key]"
                    :disabled="busy"
                    :label="t('permissions.' + key)"
                    @change="permissionValues[permissionProject][key] = $event.target.checked"
                  />
                  <p class="small muted">{{ t('permissions.' + key + 'Hint') }}</p>
                </div>
              </template>
              <button
                type="button"
                class="button secondary"
                :disabled="busy"
                @click="permissionValues[permissionProject] = { ...defaultProjectPermissions }"
              >
                {{ t('permissions.reset') }}
              </button>
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
          <div class="person-identity">
            <AppIcon name="send" :size="22" /><strong class="person-name">{{
              invitation.name || invitation.email
            }}</strong>
          </div>
          <p v-if="invitation.name" class="person-meta muted">
            <AppIcon name="mail" :size="16" /><span>{{ invitation.email }}</span>
          </p>
          <p class="small muted">
            <AppIcon name="clock" :size="15" />{{ t('team.' + invitation.status) }} ·
            {{ date(invitation.expiresAt) }}
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
              <AppIcon name="send" :size="16" />{{ t('team.resend') }}</button
            ><button class="text-button danger" :disabled="busy" @click="revoke(invitation)">
              <AppIcon name="close" :size="16" />{{ t('team.revoke') }}
            </button>
          </div>
        </article>
      </section>
    </template>
  </div>
</template>
