<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { api } from '../api/client.js'
import { toast } from '../services/toast.js'
import { isSecurityCancellation } from '../services/stepUp.js'
import { t, formatApiError, intlLocale } from '../i18n/index.js'
import UserAvatar from '../components/UserAvatar.vue'
import AppIcon from '../components/AppIcon.vue'
import LoadingButton from '../components/LoadingButton.vue'
const route = useRoute(),
  nav = useAdminNavigationStore()
const state = ref(null),
  adding = ref(false),
  selected = ref([]),
  search = ref(''),
  busy = ref(false),
  error = ref('')
const management = useProjectManagementStore()
const allowed = computed(
  () => !!nav.workspace && management.project?.permissions?.manage_team === true,
)
const root = computed(() => `/api/admin/projects/${route.params.uuid}/team`)
const available = computed(() =>
  (state.value?.available || []).filter((member) =>
    `${member.user.name} ${member.user.email}`
      .toLocaleLowerCase(intlLocale())
      .includes(search.value.trim().toLocaleLowerCase(intlLocale())),
  ),
)
const inviteEmail = ref(''),
  inviteName = ref(''),
  invitationLink = ref('')
async function copyInvitation() {
  await navigator.clipboard.writeText(invitationLink.value)
}
let revision = 0
async function load(current = revision) {
  const result = await api.request(root.value)
  if (current === revision && allowed.value) state.value = result
}
watch(
  () => [route.params.uuid, nav.workspace?.id, allowed.value],
  async () => {
    const current = ++revision
    state.value = null
    selected.value = []
    adding.value = false
    search.value = ''
    busy.value = false
    error.value = ''
    try {
      if (allowed.value) await load(current)
    } catch (cause) {
      if (current === revision && !isSecurityCancellation(cause))
        error.value = formatApiError(cause)
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => revision++)
async function action(path, method, body) {
  if (busy.value || !allowed.value) return
  const current = revision
  busy.value = true
  error.value = ''
  try {
    const result = await api.request(root.value + path, { method, body })
    invitationLink.value = result.link || ''
    if (current !== revision) return
    await load(current)
    if (current !== revision) return
    adding.value = false
    selected.value = []
    toast.success(() => t('team.saved'))
  } catch (cause) {
    if (current === revision && !isSecurityCancellation(cause)) error.value = formatApiError(cause)
  } finally {
    if (current === revision) busy.value = false
  }
}
function remove(member) {
  if (window.confirm(t('projectTeam.confirmRemove', { name: member.user.name })))
    action('/members/' + member.id, 'DELETE')
}
</script>
<template>
  <div v-if="allowed" class="team-page">
    <div class="page-heading team-heading">
      <div>
        <h2>{{ t('team.title') }}</h2>
        <p class="muted">{{ t('projectTeam.hint') }}</p>
      </div>
      <button
        class="button primary"
        :disabled="busy || !state?.available.length"
        :aria-expanded="adding"
        @click="adding = !adding"
      >
        <AppIcon name="plus" :size="18" />{{ t('projectTeam.add') }}
      </button>
    </div>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <details class="surface project-team-invite">
      <summary>{{ t('team95.invite') }}</summary>
      <form
        @submit.prevent="
          action('/invitations', 'POST', {
            email: inviteEmail,
            name: inviteName,
            generateLink: true,
          })
        "
      >
        <label class="field"
          >{{ t('team.email')
          }}<input v-model="inviteEmail" type="email" required :disabled="busy" /></label
        ><label class="field"
          >{{ t('account.name') }}<input v-model="inviteName" :disabled="busy" /></label
        ><button class="button secondary" :disabled="busy">{{ t('team95.invite') }}</button>
        <p v-if="invitationLink" class="team-invitation-link">
          <input :value="invitationLink" readonly :aria-label="t('adminEditor.getLink')" /><button
            type="button"
            class="text-button"
            @click="copyInvitation"
          >
            {{ t('integrations.copy') }}
          </button>
        </p>
      </form>
    </details>
    <template v-if="state">
      <section v-if="state.owner" class="surface team-list project-owner-card">
        <h3>{{ t('team.owner') }}</h3>
        <div class="team-identity">
          <UserAvatar :user="state.owner.user" />
          <div>
            <strong>{{ state.owner.user.name }}</strong>
            <p class="small muted">{{ state.owner.user.email }}</p>
          </div>
        </div>
      </section>
      <section class="surface team-list">
        <h3>{{ t('projectTeam.current') }}</h3>
        <p v-if="!state.members.length" class="muted">{{ t('projectTeam.emptyTeam') }}</p>
        <article
          v-for="member in state.members"
          :key="member.id"
          class="team-member project-team-member"
        >
          <div class="team-identity">
            <UserAvatar :user="member.user" />
            <div>
              <strong class="person-name">{{ member.user.name }}</strong>
              <p class="small muted">{{ member.user.email }}</p>
            </div>
          </div>
          <div class="project-team-status">
            <span class="status-pill">{{ t('team95.assigned') }}</span
            ><span>{{
              t(member.isProjectSuperadmin ? 'team95.superadminBadge' : 'team.developer')
            }}</span
            ><span :title="t('security.authenticator')"
              ><AppIcon name="shield" :size="15" /> TOTP:
              {{ t(member.security?.totp ? 'team95.connected' : 'team95.notConnected') }}</span
            ><span
              ><AppIcon name="key" :size="15" /> Passkey:
              {{ t(member.security?.passkey ? 'team95.connected' : 'team95.notConnected') }}</span
            >
          </div>
          <button class="text-button danger" :disabled="busy" @click="remove(member)">
            <AppIcon name="close" :size="16" />{{ t('projectTeam.remove') }}
          </button>
        </article>
        <p v-if="!state.members.length && !state.available.length" class="muted">
          {{ t('projectTeam.noAdministrators') }}
          <RouterLink v-if="nav.user?.membershipRole === 'owner'" to="/admin/administrators">{{
            t('administrators.title')
          }}</RouterLink>
        </p>
      </section>
      <form
        v-if="adding"
        class="surface team-list"
        @submit.prevent="action('/members', 'POST', { members: selected })"
      >
        <h3>{{ t('projectTeam.add') }}</h3>
        <label class="field" for="team-member-search"
          >{{ t('administrators.search')
          }}<input id="team-member-search" v-model="search" type="search" :disabled="busy"
        /></label>
        <fieldset class="project-choices" :disabled="busy">
          <legend>{{ t('projectTeam.available') }}</legend>
          <label v-for="member in available" :key="member.id"
            ><input v-model="selected" type="checkbox" :value="member.id" /><span
              >{{ member.user.name }} · {{ member.user.email }}</span
            ></label
          >
          <p v-if="!available.length" class="small muted">{{ t('administrators.empty') }}</p>
        </fieldset>
        <div class="team-actions">
          <LoadingButton
            type="submit"
            class="button primary"
            :busy="busy"
            :disabled="busy || !selected.length"
            >{{ t('projectTeam.add') }}</LoadingButton
          ><button type="button" class="text-button" :disabled="busy" @click="adding = false">
            {{ t('account.cancel') }}
          </button>
        </div>
      </form>
    </template>
  </div>
</template>
<style scoped>
.project-team-invite {
  margin: 1rem 0 1.5rem;
  padding: 1rem 1.25rem;
}
.project-team-invite summary {
  cursor: pointer;
  font-weight: 600;
  line-height: 1.5;
}
.project-team-invite form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
  margin-top: 1.25rem;
}
.project-team-invite .field {
  display: grid;
  gap: 0.4rem;
}
.project-team-invite form > button {
  justify-self: start;
}
.team-invitation-link {
  grid-column: 1/-1;
  display: flex;
  gap: 0.75rem;
  min-width: 0;
}
.team-invitation-link input {
  min-width: 0;
  flex: 1;
}
@media (max-width: 600px) {
  .project-team-invite form {
    grid-template-columns: 1fr;
  }
}

.project-team-member {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: start;
  gap: 16px;
}
.project-owner-card h3 {
  margin-bottom: 20px;
}
.project-team-status {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 14px;
  grid-row: 2;
}
.project-team-status span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 0.75rem;
}
.project-team-member > button {
  grid-column: 2;
  grid-row: 1 / 3;
}
@media (max-width: 600px) {
  .project-team-member {
    grid-template-columns: minmax(0, 1fr);
    align-items: start;
  }
  .project-team-member > button {
    grid-column: 1;
    grid-row: auto;
  }
}
</style>
