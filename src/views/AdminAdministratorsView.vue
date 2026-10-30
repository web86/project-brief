<script setup>
import AgreementStatus from '../components/AgreementStatus.vue'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { api } from '../api/client.js'
import { t, formatApiError, intlLocale } from '../i18n/index.js'
import { toast } from '../services/toast.js'
import { isSecurityCancellation } from '../services/stepUp.js'
import { useAdminNavigationStore } from '../stores/adminNavigation.js'
import AdministratorDialog from '../components/AdministratorDialog.vue'
import BaseDialog from '../components/BaseDialog.vue'
import AppIcon from '../components/AppIcon.vue'
import UserAvatar from '../components/UserAvatar.vue'
const nav = useAdminNavigationStore(),
  root = '/api/admin/administrators'
const allowed = computed(() => !!nav.workspace && nav.user?.membershipRole === 'owner')
const state = ref(null),
  busy = ref(false),
  error = ref(''),
  loading = ref(false),
  search = ref(''),
  query = ref(''),
  status = ref('all')
const open = ref(false),
  kind = ref('create'),
  record = ref(null),
  modalError = ref(''),
  duplicate = ref(null),
  link = ref(''),
  copying = ref(false)
let revision = 0,
  timer
watch(search, (v) => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    query.value = v
  }, 300)
})
function matches(name, email) {
  return `${name} ${email}`
    .toLocaleLowerCase(intlLocale())
    .includes(query.value.trim().toLocaleLowerCase(intlLocale()))
}
const members = computed(() =>
  (state.value?.members || []).filter(
    (m) =>
      m.role !== 'owner' &&
      (status.value === 'all' || status.value === m.status) &&
      matches(m.user.name, m.user.email),
  ),
)
const invitations = computed(() =>
  (state.value?.invitations || []).filter(
    (i) => (status.value === 'all' || status.value === 'invited') && matches(i.name || '', i.email),
  ),
)
function edit(item = null, type = 'create') {
  record.value = item
  kind.value = type
  modalError.value = ''
  duplicate.value = null
  open.value = true
}
async function load(current = revision) {
  const result = await api.request(root)
  if (current === revision && allowed.value) state.value = result
}
watch(
  () => [nav.workspace?.id, allowed.value],
  async () => {
    const current = ++revision
    state.value = null
    open.value = false
    link.value = ''
    error.value = ''
    busy.value = false
    search.value = ''
    query.value = ''
    status.value = 'all'
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
  link.value = ''
})
async function action(path, method, body, { close = false, success = 'team.saved' } = {}) {
  if (busy.value || !allowed.value) return
  const current = revision
  busy.value = true
  modalError.value = ''
  error.value = ''
  duplicate.value = null
  try {
    const result = await api.request(root + path, { method, body })
    if (current !== revision) return
    await load(current)
    if (current !== revision) return
    if (close) open.value = false
    else if (record.value && kind.value === 'invitation')
      record.value = state.value.invitations.find((i) => i.id === record.value.id) || record.value
    if (result.link) link.value = result.link
    toast.success(() => t(success))
    return result
  } catch (cause) {
    if (current !== revision || isSecurityCancellation(cause)) return
    let message =
      cause.code === 'account_mail_failed' ? t('account.mailFailed') : formatApiError(cause)
    if (['administrator_exists', 'administrator_invitation_exists'].includes(cause.code)) {
      await load(current).catch(() => {})
      if (current !== revision) return
      message = t(
        cause.code === 'administrator_exists'
          ? 'administrators.exists'
          : 'administrators.invitationExists',
      )
      const member = state.value?.members.find(
        (m) => m.id === cause.errors?.memberId && m.role !== 'owner',
      )
      const invitation = state.value?.invitations.find((i) => i.id === cause.errors?.invitationId)
      duplicate.value = member
        ? { record: member, kind: 'member' }
        : invitation
          ? { record: invitation, kind: 'invitation' }
          : null
    }
    if (open.value) modalError.value = message
    else error.value = message
    if (cause.code === 'account_mail_failed') await load(current).catch(() => {})
  } finally {
    if (current === revision) busy.value = false
  }
}
function save(data) {
  if (kind.value === 'create') return action('/invitations', 'POST', data, { close: true })
  const { email, sendEmail, generateLink, status: memberStatus, ...values } = data
  return action(
    `/${kind.value === 'member' ? 'members' : 'invitations'}/${record.value.id}`,
    'PATCH',
    { ...values, ...(kind.value === 'member' ? { status: memberStatus } : {}) },
    { close: true },
  )
}
function remove() {
  if (
    window.confirm(
      t('adminEditor.confirmDelete', {
        name: record.value?.user?.name || record.value?.name || record.value?.email,
      }),
    )
  )
    return action(
      `/${kind.value === 'member' ? 'members' : 'invitations'}/${record.value.id}`,
      'DELETE',
      undefined,
      { close: true },
    )
}
function invitationAction(mode) {
  return action(`/invitations/${record.value.id}/${mode}`, 'POST', undefined, {
    success: mode === 'link' ? 'adminEditor.linkReady' : 'team.sent',
  })
}
async function copyLink() {
  const value = link.value,
    current = revision
  if (!value || copying.value) return
  copying.value = true
  try {
    await navigator.clipboard.writeText(value)
    if (current === revision && value === link.value) toast.success(() => t('integrations.copied'))
  } catch {
    if (current === revision) toast.error(() => t('ui.weCouldNotCompleteThisActionPleaseTry'))
  } finally {
    if (current === revision) copying.value = false
  }
}
</script>
<template>
  <div v-if="allowed" class="administrators-page team-page">
    <div class="page-heading team-heading">
      <div>
        <h1>{{ t('administrators.title') }}</h1>
        <p class="muted">{{ t('administrators.hint') }}</p>
      </div>
      <button class="button primary" :disabled="busy" @click="edit()">
        <AppIcon name="plus" :size="18" />{{ t('administrators.add') }}
      </button>
    </div>
    <div class="administrator-filters surface">
      <label class="field" for="administrator-search"
        >{{ t('administrators.search')
        }}<input id="administrator-search" v-model="search" type="search" /></label
      ><label class="field" for="administrator-status"
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
    <p v-if="loading" role="status" class="muted">{{ t('nav.loading') }}</p>
    <button
      v-if="!state && !loading"
      class="button secondary"
      @click="load().catch((cause) => (error = formatApiError(cause)))"
    >
      {{ t('integrations.refresh') }}
    </button>
    <template v-if="state"
      ><p
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
        >
          <div class="team-identity">
            <UserAvatar :user="member.user" />
            <div>
              <strong class="person-name">{{ member.user.name }}</strong>
              <p class="small muted">{{ member.user.email }}</p>
            </div>
          </div>
          <div class="person-badges">
            <span class="client-state">{{ t('adminEditor.' + member.status) }}</span
            ><span class="person-role">{{
              t('administrators.projectCount', { count: member.projects.length })
            }}</span>
          </div>
          <p v-if="!member.projects.length" class="small muted">
            {{ t('administrators.noProjects') }}
          </p>
          <p v-if="member.isProjectSuperadmin" class="small">
            <AppIcon name="shield" :size="15" /> {{ t('team95.superadminBadge') }}
          </p>
          <AgreementStatus :agreement="member.agreement" />
          <div class="administrator-security small muted">
            <span :title="t('security.authenticator')"
              ><AppIcon name="shield" :size="15" /><span class="sr-only">{{
                t('security.authenticator')
              }}</span
              ><span class="sr-only">{{ t('security.authenticator') }}</span
              >TOTP <AppIcon :name="member.security.totp ? 'check' : 'close'" :size="14" /><span
                class="sr-only"
                >{{
                  t(member.security.totp ? 'security.configured' : 'security.notConfigured')
                }}</span
              ></span
            ><span :title="t('security.passkeys')"
              >{{ t('security.passkeys') }}
              <AppIcon :name="member.security.passkey ? 'check' : 'close'" :size="14" /><span
                class="sr-only"
                >{{
                  t(member.security.passkey ? 'security.configured' : 'security.notConfigured')
                }}</span
              ></span
            >
          </div>
          <button
            class="button secondary administrator-manage"
            :disabled="busy"
            @click="edit(member, 'member')"
          >
            <AppIcon name="edit" :size="16" />{{ t('adminEditor.edit') }}
          </button>
        </article>
        <article
          v-for="invitation in invitations"
          :key="invitation.id"
          class="surface administrator-card team-member"
        >
          <div class="team-identity">
            <UserAvatar :user="{ name: invitation.name || invitation.email }" />
            <div>
              <strong class="person-name">{{ invitation.name || invitation.email }}</strong>
              <p class="small muted">{{ invitation.email }}</p>
            </div>
          </div>
          <div class="person-badges">
            <span class="client-state">{{ t('adminEditor.invited') }}</span
            ><span class="person-role">{{
              t('administrators.projectCount', { count: invitation.projects.length })
            }}</span>
          </div>
          <p class="small muted">
            {{
              t(
                invitation.status === 'draft'
                  ? 'administrators.draft'
                  : 'team.' + invitation.status,
              )
            }}
          </p>
          <AgreementStatus :agreement="invitation.agreement" />
          <div
            class="administrator-security small muted"
            :title="t('adminEditor.pendingSecurity')"
            :aria-label="t('adminEditor.pendingSecurity')"
          >
            <span>TOTP —</span><span>{{ t('security.passkeys') }} —</span>
          </div>
          <button
            class="button secondary administrator-manage"
            :disabled="busy"
            @click="edit(invitation, 'invitation')"
          >
            <AppIcon name="edit" :size="16" />{{ t('adminEditor.edit') }}
          </button>
        </article>
      </div>
    </template>
    <AdministratorDialog
      :open="open"
      :kind="kind"
      :record="record"
      :projects="state?.projects || []"
      :busy="busy"
      :error="modalError"
      @close="open = false"
      @save="save"
      @remove="remove"
      @invite="invitationAction('resend')"
      @link="invitationAction('link')"
      ><template #error-actions
        ><button
          v-if="duplicate"
          type="button"
          class="button secondary"
          @click="edit(duplicate.record, duplicate.kind)"
        >
          {{ t('adminEditor.openExisting') }}
        </button></template
      ></AdministratorDialog
    >
    <BaseDialog
      :open="!!link"
      :title="t('adminEditor.linkReady')"
      id="invitation-link-title"
      class="management-dialog invitation-link-dialog"
      @close="link = ''"
    >
      <div class="management-body">
        <p>{{ t('adminEditor.linkHint') }}</p>
        <label class="field" for="administrator-invitation-link"
          >{{ t('adminEditor.invitationLink')
          }}<textarea id="administrator-invitation-link" :value="link" readonly rows="3" />
        </label>
        <p class="small muted">{{ t('adminEditor.linkOnce') }}</p>
      </div>
      <template #footer
        ><button class="button secondary" @click="link = ''">{{ t('integrations.close') }}</button
        ><button class="button primary" :disabled="copying" @click="copyLink">
          {{ t('integrations.copy') }}
        </button></template
      >
    </BaseDialog>
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
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
  gap: 20px;
  align-items: start;
}
.administrator-card {
  min-width: 0;
  padding: 20px;
  overflow-wrap: anywhere;
  margin: 0;
}
.administrator-security {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  margin: 12px 0;
}
.administrator-security > span {
  display: inline-flex;
  gap: 5px;
  align-items: center;
}
.administrator-manage {
  margin-top: 8px;
}
.administrator-card .person-badges {
  margin-top: 14px;
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
