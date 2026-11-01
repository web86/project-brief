<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import { agentIcon } from '../services/agentPresentation.js'
import { formatDate } from '../constants/project.js'
import { api } from '../api/client.js'
import { toast } from '../services/toast.js'
import { isSecurityCancellation } from '../services/stepUp.js'
import { t, formatApiError, intlLocale } from '../i18n/index.js'
import UserAvatar from '../components/UserAvatar.vue'
import AppIcon from '../components/AppIcon.vue'
import LoadingButton from '../components/LoadingButton.vue'
import BaseDialog from '../components/BaseDialog.vue'
const route = useRoute(),
  nav = useAdminNavigationStore()
const state = ref(null),
  adding = ref(false),
  addTab = ref('existing'),
  agentAdding = ref(false),
  selectedAgent = ref(''),
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
  inviteName = ref('')
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
    inviteEmail.value = ''
    inviteName.value = ''
    agentAdding.value = false
    selectedAgent.value = ''
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
    await api.request(root.value + path, { method, body })
    if (current !== revision) return
    await load(current)
    if (current !== revision) return
    adding.value = false
    inviteEmail.value = ''
    inviteName.value = ''
    agentAdding.value = false
    selectedAgent.value = ''
    selected.value = []
    toast.success(() => t('team.saved'))
  } catch (cause) {
    if (current === revision && !isSecurityCancellation(cause)) error.value = formatApiError(cause)
  } finally {
    if (current === revision) busy.value = false
  }
}
function openAdd() {
  addTab.value = state.value.available.length ? 'existing' : 'invite'
  adding.value = true
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
        :disabled="busy || !state"
        :aria-expanded="adding"
        @click="openAdd"
      >
        <AppIcon name="plus" :size="18" />{{ t('projectTeam.add') }}
      </button>
    </div>
    <p v-if="error && !adding" class="field-error" role="alert">{{ error }}</p>
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
      <section class="surface team-list team-agent-section" data-team-agents>
        <h3>{{ t('agentUx.agents') }}</h3>
        <article
          v-for="agent in state.agents || []"
          :key="agent.id"
          class="team-member project-team-member"
        >
          <div class="team-identity">
            <span class="task-account-avatar user-avatar"
              ><AppIcon :name="agentIcon(agent.kind)" :size="24"
            /></span>
            <div>
              <strong>{{ agent.name }}</strong>
              <p class="small muted">{{ t('agents.kind.' + agent.kind) }}</p>
            </div>
          </div>
          <div class="project-team-status">
            <span class="status-pill">{{
              t(agent.active ? 'agents.active' : 'agents.disabled')
            }}</span
            ><span v-if="agent.lastActivityAt"
              >{{ t('agents.lastActivity') }}:
              {{ agent.lastActivityAt ? formatDate(agent.lastActivityAt) : '—' }}</span
            >
          </div>
          <button
            class="text-button danger"
            :disabled="busy"
            @click="action('/agents/' + agent.id, 'DELETE')"
          >
            {{ t('projectTeam.remove') }}
          </button>
        </article>
        <p v-if="!state.agents?.length" class="muted">{{ t('agents.noExecutors') }}</p>
        <button
          v-if="!agentAdding"
          class="button secondary"
          :disabled="busy || !state.availableAgents?.length"
          :aria-expanded="agentAdding"
          @click="agentAdding = true"
        >
          <AppIcon name="plus" :size="16" />{{ t('agents.addExecutor') }}
        </button>
        <form
          v-else
          class="team-agent-picker"
          @submit.prevent="action('/agents/' + selectedAgent, 'POST')"
        >
          <label class="field" for="team-agent-choice"
            >{{ t('agents.choose')
            }}<select id="team-agent-choice" v-model="selectedAgent" required :disabled="busy">
              <option value="" disabled>{{ t('agents.choose') }}</option>
              <option
                v-for="agent in state.availableAgents || []"
                :key="agent.id"
                :value="agent.id"
              >
                {{ agent.name }} · {{ t('agents.kind.' + agent.kind) }}
              </option>
            </select></label
          ><button class="button primary" :disabled="busy || !selectedAgent">
            {{ t('agents.addExecutor') }}</button
          ><button type="button" class="text-button" :disabled="busy" @click="agentAdding = false">
            {{ t('ui.cancel') }}
          </button>
        </form>
      </section>
    </template>
    <BaseDialog
      :open="adding"
      :title="t('projectTeam.add')"
      id="team-add-title"
      class="management-dialog team-add-dialog"
      @close="!busy && (adding = false)"
    >
      <div class="management-body">
        <div role="tablist" class="team-add-tabs" :aria-label="t('projectTeam.add')">
          <button
            v-for="tab in ['existing', 'invite']"
            :id="'team-add-' + tab + '-tab'"
            :key="tab"
            type="button"
            role="tab"
            :aria-selected="addTab === tab"
            :aria-controls="'team-add-' + tab"
            class="button"
            :class="addTab === tab ? 'primary' : 'secondary'"
            :disabled="busy"
            @click="addTab = tab"
          >
            {{ t(tab === 'existing' ? 'release910.existingMember' : 'release910.inviteMember') }}
          </button>
        </div>
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <form
          v-if="addTab === 'existing'"
          id="team-add-existing"
          role="tabpanel"
          aria-labelledby="team-add-existing-tab"
          @submit.prevent="action('/members', 'POST', { members: selected })"
        >
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
        </form>
        <form
          v-else
          id="team-add-invite"
          role="tabpanel"
          aria-labelledby="team-add-invite-tab"
          @submit.prevent="
            action('/invitations', 'POST', {
              email: inviteEmail,
              name: inviteName,
            })
          "
        >
          <label class="field"
            >{{ t('team.email')
            }}<input v-model="inviteEmail" type="email" required :disabled="busy" /></label
          ><label class="field"
            >{{ t('account.name') }}<input v-model="inviteName" :disabled="busy"
          /></label>
        </form>
      </div>
      <template #footer
        ><button type="button" class="button secondary" :disabled="busy" @click="adding = false">
          {{ t('ui.cancel') }}</button
        ><LoadingButton
          :form="addTab === 'existing' ? 'team-add-existing' : 'team-add-invite'"
          type="submit"
          class="button primary"
          :busy="busy"
          :disabled="busy || (addTab === 'existing' && !selected.length)"
          >{{ t(addTab === 'existing' ? 'projectTeam.add' : 'team95.invite') }}</LoadingButton
        ></template
      >
    </BaseDialog>
  </div>
</template>
<style scoped>
.team-agent-section {
  display: grid;
  gap: 16px;
}
.team-agent-section h2,
.team-agent-section p {
  margin-top: 0;
  margin-bottom: 0;
}
.team-agent-section .project-team-member {
  padding: 16px;
  gap: 16px;
  margin: 0;
}
.team-agent-section > .button {
  justify-self: start;
}
.team-agent-picker {
  display: flex;
  align-items: end;
  flex-wrap: wrap;
  gap: 10px;
}
.team-agent-picker .field {
  flex: 1;
  min-width: 160px;
  margin: 0;
}

.team-add-dialog {
  width: min(900px, calc(100vw - 32px));
}
.team-add-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.team-add-dialog form {
  display: grid;
  gap: 16px;
  min-width: 0;
}
.team-add-dialog .field {
  margin: 0;
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
