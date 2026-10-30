<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError, intlLocale } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import { isSecurityCancellation } from '../services/stepUp.js'
import { defaultProjectPermissions } from '../services/projectPermissions.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import NotificationToggle from '../components/NotificationToggle.vue'
import AppIcon from '../components/AppIcon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import LoadingButton from '../components/LoadingButton.vue'
const nav = useAdminNavigationStore()
const allowed = computed(() => !!nav.workspace && nav.user?.membershipRole === 'owner')
const root = '/api/admin/administrators'
const state = ref(null),
  busy = ref(false),
  error = ref(''),
  loading = ref(false)
const adding = ref(false),
  email = ref(''),
  name = ref(''),
  sendEmail = ref(true)
const search = ref(''),
  query = ref(''),
  status = ref('all'),
  notice = ref(''),
  highlighted = ref('')
const editing = ref(null),
  editName = ref(''),
  selected = ref([]),
  projectSearch = ref(''),
  canCreate = ref(false),
  permissionProject = ref(''),
  permissionValues = ref({})
const keys = Object.keys(defaultProjectPermissions)
let revision = 0,
  timer
watch(search, (value) => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    query.value = value
  }, 300)
})
function matches(name, email) {
  return `${name} ${email}`
    .toLocaleLowerCase(intlLocale())
    .includes(query.value.trim().toLocaleLowerCase(intlLocale()))
}
const members = computed(() =>
  (state.value?.members || []).filter(
    (member) =>
      member.role !== 'owner' &&
      (status.value === 'all' || status.value === member.status) &&
      matches(member.user.name, member.user.email),
  ),
)
const invitations = computed(() =>
  (state.value?.invitations || []).filter(
    (item) =>
      (status.value === 'all' || status.value === 'invited') &&
      matches(item.name || '', item.email),
  ),
)
const projects = computed(() =>
  (state.value?.projects || []).filter((project) =>
    project.name
      .toLocaleLowerCase(intlLocale())
      .includes(projectSearch.value.trim().toLocaleLowerCase(intlLocale())),
  ),
)
const assigned = computed(() =>
  (state.value?.projects || []).filter((project) => selected.value.includes(project.id)),
)
watch(
  selected,
  (ids) => {
    for (const id of ids) permissionValues.value[id] ||= { ...defaultProjectPermissions }
    if (!ids.includes(permissionProject.value)) permissionProject.value = ids[0] || ''
  },
  { deep: true },
)
async function load(current = revision) {
  const result = await api.request(root)
  if (current === revision && allowed.value) state.value = result
}
watch(
  () => [nav.workspace?.id, allowed.value],
  async () => {
    const current = ++revision
    state.value = null
    error.value = ''
    notice.value = ''
    highlighted.value = ''
    editing.value = null
    adding.value = false
    name.value = ''
    email.value = ''
    search.value = ''
    query.value = ''
    status.value = 'all'
    busy.value = false
    clearTimeout(timer)
    loading.value = true
    try {
      if (allowed.value) await load(current)
    } catch (cause) {
      if (current === revision && !isSecurityCancellation(cause))
        error.value = formatApiError(cause)
    } finally {
      if (current === revision) loading.value = false
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  revision++
  clearTimeout(timer)
})
async function action(path, method, body, successKey = 'team.saved') {
  if (busy.value || !allowed.value) return
  const current = revision
  busy.value = true
  error.value = ''
  notice.value = ''
  highlighted.value = ''
  try {
    const result = await api.request(root + path, { method, body })
    if (current !== revision) return
    await load(current)
    if (current !== revision) return
    toast.success(() => t(successKey))
    return result
  } catch (cause) {
    if (current !== revision || isSecurityCancellation(cause)) return
    await load(current).catch(() => {})
    if (current !== revision) return
    if (cause.code === 'administrator_exists' || cause.code === 'administrator_invitation_exists') {
      search.value = ''
      query.value = ''
      status.value = 'all'
      notice.value = t(
        cause.code === 'administrator_exists'
          ? 'administrators.exists'
          : 'administrators.invitationExists',
      )
      highlighted.value = String(cause.errors?.memberId || cause.errors?.invitationId || '')
      const member = state.value?.members.find((item) => item.id === cause.errors?.memberId)
      if (member?.role === 'admin') edit(member)
    } else {
      error.value =
        cause.code === 'account_mail_failed' ? t('account.mailFailed') : formatApiError(cause)
      toast.error(() => formatApiError(cause))
    }
  } finally {
    if (current === revision) busy.value = false
  }
}
async function add() {
  const result = await action(
    '/invitations',
    'POST',
    { name: name.value, email: email.value, sendEmail: sendEmail.value },
    sendEmail.value ? 'team.sent' : 'administrators.draftSaved',
  )
  if (result) {
    adding.value = false
    name.value = ''
    email.value = ''
  }
}
function edit(member) {
  editing.value = member.id
  editName.value = member.user.name
  selected.value = [...member.projects]
  canCreate.value = member.canCreateProjects
  projectSearch.value = ''
  permissionValues.value = Object.fromEntries(
    member.projects.map((id) => [
      id,
      { ...defaultProjectPermissions, ...member.projectPermissions?.[id] },
    ]),
  )
  permissionProject.value = member.projects[0] || ''
}
async function save(member) {
  const result = await action('/members/' + member.id, 'PATCH', {
    name: editName.value,
    projects: selected.value,
    canCreateProjects: canCreate.value,
    projectPermissions: Object.fromEntries(
      selected.value.map((id) => [id, permissionValues.value[id]]),
    ),
  })
  if (result) editing.value = null
}
function editInvitation(invitation) {
  editing.value = invitation.id
  editName.value = invitation.name || ''
  selected.value = [...invitation.projects]
  projectSearch.value = ''
}
async function saveInvitation(invitation) {
  const result = await action('/invitations/' + invitation.id, 'PATCH', {
    name: editName.value,
    projects: selected.value,
  })
  if (result) editing.value = null
}
function deactivate(member) {
  if (window.confirm(t('administrators.confirmDeactivate', { name: member.user.name })))
    action('/members/' + member.id, 'DELETE')
}
function revoke(invitation) {
  if (window.confirm(t('team.confirmRevoke'))) action('/invitations/' + invitation.id, 'DELETE')
}
function reinvite(member) {
  adding.value = true
  name.value = member.user.name
  email.value = member.user.email
  sendEmail.value = true
}
</script>
<template>
  <div v-if="allowed" class="administrators-page team-page">
    <div class="page-heading team-heading">
      <div>
        <h1>{{ t('administrators.title') }}</h1>
        <p class="muted">{{ t('administrators.hint') }}</p>
      </div>
      <button
        class="button primary"
        :disabled="busy"
        :aria-expanded="adding"
        @click="adding = !adding"
      >
        <AppIcon name="plus" :size="18" />{{ t('administrators.add') }}
      </button>
    </div>
    <div class="administrator-filters surface">
      <label class="field" for="administrator-search"
        >{{ t('administrators.search')
        }}<input id="administrator-search" v-model="search" type="search"
      /></label>
      <label class="field" for="administrator-status"
        >{{ t('administrators.status')
        }}<select id="administrator-status" v-model="status">
          <option
            v-for="value in ['all', 'active', 'invited', 'inactive']"
            :key="value"
            :value="value"
          >
            {{ t('administrators.' + value) }}
          </option>
        </select></label
      >
    </div>
    <p v-if="error" role="alert" class="field-error">{{ error }}</p>
    <p v-if="notice" role="status" class="account-success">{{ notice }}</p>
    <p v-if="loading" role="status" class="muted">{{ t('nav.loading') }}</p>
    <button
      v-if="!state && !loading"
      class="button secondary"
      @click="load().catch((cause) => (error = formatApiError(cause)))"
    >
      {{ t('integrations.refresh') }}
    </button>
    <template v-if="state">
      <form v-if="adding" class="surface admin-form invitation-form" @submit.prevent="add">
        <h2>{{ t('administrators.add') }}</h2>
        <label class="field" for="administrator-name"
          >{{ t('administrators.name')
          }}<input id="administrator-name" v-model="name" maxlength="255" :disabled="busy" required
        /></label>
        <label class="field" for="administrator-email"
          >{{ t('team.email')
          }}<input
            id="administrator-email"
            v-model="email"
            type="email"
            maxlength="254"
            :disabled="busy"
            required
        /></label>
        <label class="administrator-check"
          ><input v-model="sendEmail" type="checkbox" :disabled="busy" />{{
            t('administrators.sendEmail')
          }}</label
        >
        <p class="small muted">{{ t('administrators.inviteHint') }}</p>
        <LoadingButton type="submit" class="button primary" :busy="busy" :disabled="busy">{{
          t(sendEmail ? 'team.send' : 'account.save')
        }}</LoadingButton>
      </form>
      <p
        v-if="!members.length && !invitations.length"
        class="surface team-list muted"
        role="status"
      >
        {{ t('administrators.empty') }}
      </p>
      <div class="administrator-grid">
        <article
          v-for="member in members"
          :key="member.id"
          class="surface administrator-card team-member"
          :class="{ 'administrator-highlight': highlighted === String(member.id) }"
        >
          <div class="team-identity">
            <UserAvatar :user="member.user" />
            <div>
              <strong class="person-name">{{ member.user.name }}</strong>
              <p class="small muted">{{ member.user.email }}</p>
            </div>
          </div>
          <div class="person-badges">
            <span class="client-state">{{ t('administrators.' + member.status) }}</span
            ><span class="person-role">{{
              t('administrators.projectCount', { count: member.projects.length })
            }}</span>
          </div>
          <p v-if="!member.projects.length" class="small muted">
            {{ t('administrators.noProjects') }}
          </p>
          <p class="small muted security-indicators">
            <span
              ><AppIcon name="shield" :size="15" />{{ t('security.authenticator') }}:
              {{ t(member.security.totp ? 'security.configured' : 'security.notConfigured') }}</span
            ><span
              ><AppIcon name="shield" :size="15" />{{ t('security.passkeys') }}:
              {{
                t(member.security.passkey ? 'security.configured' : 'security.notConfigured')
              }}</span
            >
          </p>
          <p class="small">
            {{ t('permissions.createProjects') }}:
            {{ t(member.canCreateProjects ? 'administrators.yes' : 'administrators.no') }}
          </p>
          <button
            v-if="member.status === 'active'"
            class="button secondary administrator-manage"
            :disabled="busy"
            :aria-expanded="editing === member.id"
            @click="editing === member.id ? (editing = null) : edit(member)"
          >
            <AppIcon name="settings" :size="16" />{{ t('administrators.manage') }}
          </button>
          <button v-else class="button secondary" :disabled="busy" @click="reinvite(member)">
            {{ t('administrators.reinvite') }}
          </button>
          <form
            v-if="editing === member.id"
            class="team-edit administrator-editor"
            @submit.prevent="save(member)"
          >
            <label class="field" :for="'administrator-name-' + member.id"
              >{{ t('administrators.name')
              }}<input
                :id="'administrator-name-' + member.id"
                v-model="editName"
                maxlength="255"
                required
                :disabled="busy"
            /></label>
            <p class="small muted">{{ t('administrators.profileHint') }}</p>
            <fieldset class="project-choices" :disabled="busy">
              <legend>{{ t('team.projects') }}</legend>
              <label class="field" :for="'administrator-project-search-' + member.id"
                >{{ t('integrations.searchProjects')
                }}<input
                  :id="'administrator-project-search-' + member.id"
                  v-model="projectSearch"
                  type="search"
              /></label>
              <div class="administrator-project-options">
                <label v-for="project in projects" :key="project.id"
                  ><input v-model="selected" type="checkbox" :value="project.id" />{{
                    project.name
                  }}</label
                >
              </div>
              <p v-if="!selected.length" class="small muted">
                {{ t('administrators.noProjects') }}
              </p>
            </fieldset>
            <div class="member-creation-permission">
              <NotificationToggle
                :checked="canCreate"
                :disabled="busy"
                :label="t('permissions.createProjects')"
                @change="canCreate = $event.target.checked"
              />
            </div>
            <fieldset v-if="assigned.length" class="member-permissions" :disabled="busy">
              <legend>{{ t('permissions.title') }}</legend>
              <label class="field" :for="'administrator-permission-project-' + member.id"
                >{{ t('permissions.project')
                }}<select
                  :id="'administrator-permission-project-' + member.id"
                  v-model="permissionProject"
                >
                  <option v-for="project in assigned" :key="project.id" :value="project.id">
                    {{ project.name }}
                  </option>
                </select></label
              >
              <p class="small muted">{{ t('permissions.hint') }}</p>
              <template v-if="permissionValues[permissionProject]"
                ><div v-for="key in keys" :key="key" class="member-permission">
                  <NotificationToggle
                    :checked="permissionValues[permissionProject][key]"
                    :disabled="busy"
                    :label="t('permissions.' + key)"
                    @change="permissionValues[permissionProject][key] = $event.target.checked"
                  />
                  <p class="small muted">{{ t('permissions.' + key + 'Hint') }}</p>
                </div></template
              >
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
              <LoadingButton type="submit" class="button primary" :busy="busy" :disabled="busy">{{
                t('account.save')
              }}</LoadingButton
              ><button class="text-button" type="button" :disabled="busy" @click="editing = null">
                {{ t('account.cancel') }}
              </button>
            </div>
            <button
              class="text-button danger"
              type="button"
              :disabled="busy"
              @click="deactivate(member)"
            >
              {{ t('administrators.deactivate') }}
            </button>
          </form>
        </article>
        <article
          v-for="invitation in invitations"
          :key="invitation.id"
          class="surface administrator-card team-member"
          :class="{ 'administrator-highlight': highlighted === invitation.id }"
        >
          <div class="team-identity">
            <UserAvatar :user="{ name: invitation.name || invitation.email }" />
            <div>
              <strong class="person-name">{{ invitation.name || invitation.email }}</strong>
              <p class="small muted">{{ invitation.email }}</p>
            </div>
          </div>
          <div class="person-badges">
            <span class="client-state">{{
              t(
                invitation.status === 'draft'
                  ? 'administrators.draft'
                  : 'team.' + invitation.status,
              )
            }}</span
            ><span class="person-role">{{
              t('administrators.projectCount', { count: invitation.projects.length })
            }}</span>
          </div>
          <p v-if="!invitation.projects.length" class="small muted">
            {{ t('administrators.noProjects') }}
          </p>
          <button
            class="button secondary"
            :disabled="busy"
            :aria-expanded="editing === invitation.id"
            @click="editing === invitation.id ? (editing = null) : editInvitation(invitation)"
          >
            {{ t('administrators.manage') }}
          </button>
          <form
            v-if="editing === invitation.id"
            class="team-edit administrator-editor"
            @submit.prevent="saveInvitation(invitation)"
          >
            <label class="field" for="invitation-edit-name"
              >{{ t('administrators.name')
              }}<input
                id="invitation-edit-name"
                v-model="editName"
                maxlength="255"
                :disabled="busy"
            /></label>
            <fieldset class="project-choices" :disabled="busy">
              <legend>{{ t('team.projects') }}</legend>
              <label class="field" for="invitation-project-search"
                >{{ t('integrations.searchProjects')
                }}<input id="invitation-project-search" v-model="projectSearch" type="search"
              /></label>
              <div class="administrator-project-options">
                <label v-for="project in projects" :key="project.id"
                  ><input v-model="selected" type="checkbox" :value="project.id" />{{
                    project.name
                  }}</label
                >
              </div>
              <p v-if="!selected.length" class="small muted">
                {{ t('administrators.noProjects') }}
              </p>
            </fieldset>
            <LoadingButton type="submit" class="button primary" :busy="busy" :disabled="busy">{{
              t('account.save')
            }}</LoadingButton>
          </form>
          <div class="team-actions">
            <button
              class="button secondary"
              :disabled="busy"
              @click="
                action('/invitations/' + invitation.id + '/resend', 'POST', undefined, 'team.sent')
              "
            >
              {{ t(invitation.status === 'draft' ? 'team.send' : 'team.resend') }}</button
            ><button class="text-button danger" :disabled="busy" @click="revoke(invitation)">
              {{ t('team.revoke') }}
            </button>
          </div>
        </article>
      </div>
    </template>
  </div>
</template>
<style scoped>
.administrator-filters {
  display: flex;
  gap: 16px;
  padding: 16px;
  margin-bottom: 24px;
  align-items: end;
}
.administrator-filters .field {
  margin: 0;
  min-width: 0;
  flex: 1;
}
.administrator-filters .field:first-child {
  flex: 3;
}
.administrator-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr));
  gap: 20px;
  align-items: start;
}
.administrator-card {
  min-width: 0;
  padding: 20px;
  overflow-wrap: anywhere;
}
.administrator-highlight {
  outline: 2px solid var(--action);
}
.administrator-check,
.security-indicators span {
  display: flex;
  gap: 8px;
  align-items: center;
}
.security-indicators {
  display: grid;
  gap: 6px;
}
.administrator-project-options {
  max-height: 260px;
  overflow-y: auto;
  display: grid;
  gap: 12px;
}
.administrator-editor {
  display: grid;
  gap: 16px;
  border-top: 1px solid var(--divider);
  padding-top: 20px;
}
@media (max-width: 600px) {
  .administrator-filters {
    flex-direction: column;
    align-items: stretch;
  }
  .administrator-card {
    padding: 16px;
  }
}
</style>
