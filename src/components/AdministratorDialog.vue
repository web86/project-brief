<script setup>
import AgreementStatus from './AgreementStatus.vue'
import { computed, nextTick, ref, watch } from 'vue'
import BaseDialog from './BaseDialog.vue'
import NotificationToggle from './NotificationToggle.vue'
import LoadingButton from './LoadingButton.vue'
import { t, intlLocale } from '../i18n/index.js'
const props = defineProps({
  open: Boolean,
  kind: String,
  record: Object,
  projects: { type: Array, default: () => [] },
  busy: Boolean,
  error: String,
})
const errorAlert = ref(null)
watch(
  () => props.error,
  async (value) => {
    if (value) {
      await nextTick()
      errorAlert.value?.focus()
    }
  },
)
const emit = defineEmits(['close', 'save', 'remove', 'invite', 'link'])
const name = ref(''),
  email = ref(''),
  status = ref('active'),
  superadmin = ref(false),
  selected = ref([]),
  search = ref(''),
  delivery = ref('email')
const visible = computed(() =>
  props.projects.filter((p) =>
    p.name
      .toLocaleLowerCase(intlLocale())
      .includes(search.value.trim().toLocaleLowerCase(intlLocale())),
  ),
)
watch(
  () => [props.open, props.kind, props.record?.id],
  () => {
    if (!props.open) return
    const r = props.record
    name.value = r?.user?.name || r?.name || ''
    email.value = r?.user?.email || r?.email || ''
    status.value = r?.status === 'inactive' ? 'inactive' : 'active'
    superadmin.value = r?.isProjectSuperadmin === true
    selected.value = [...(r?.projects || [])]
    search.value = ''
    delivery.value = 'email'
  },
  { immediate: true },
)
function save() {
  emit('save', {
    name: name.value,
    email: email.value,
    status: status.value,
    projects: selected.value,
    isProjectSuperadmin: superadmin.value,
    sendEmail: delivery.value === 'email',
    generateLink: delivery.value === 'link',
  })
}
</script>
<template>
  <BaseDialog
    :open="open"
    :title="t(kind === 'create' ? 'administrators.add' : 'adminEditor.edit')"
    id="administrator-dialog-title"
    class="management-dialog administrator-dialog"
    @close="emit('close')"
  >
    <form
      id="administrator-editor"
      class="management-body administrator-editor"
      @submit.prevent="save"
    >
      <div v-if="error" ref="errorAlert" class="field-error" role="alert" tabindex="-1">
        {{ error }}<slot name="error-actions" />
      </div>
      <div class="management-fields">
        <label class="field" for="administrator-name"
          >{{ t('administrators.name')
          }}<input
            id="administrator-name"
            v-model="name"
            maxlength="255"
            required
            :disabled="busy"
            autocomplete="name"
        /></label>
        <label class="field" for="administrator-email"
          >{{ t('team.email')
          }}<input
            id="administrator-email"
            v-model="email"
            type="email"
            maxlength="254"
            required
            :readonly="kind !== 'create'"
            :disabled="busy"
            autocomplete="email"
        /></label>
        <label
          v-if="kind === 'member'"
          class="field management-short"
          for="administrator-member-status"
          >{{ t('administrators.status')
          }}<select id="administrator-member-status" v-model="status" :disabled="busy">
            <option value="active">{{ t('adminEditor.active') }}</option>
            <option value="inactive">{{ t('adminEditor.inactive') }}</option>
          </select></label
        >
      </div>
      <p v-if="kind === 'member'" class="small muted">{{ t('administrators.profileHint') }}</p>
      <div class="member-creation-permission">
        <NotificationToggle
          :checked="superadmin"
          :disabled="busy"
          :label="t('team95.superadmin')"
          @change="superadmin = $event.target.checked"
        />
        <p class="small muted">{{ t('team95.superadminHint') }}</p>
      </div>
      <details class="management-section assigned-projects">
        <summary>{{ t('adminEditor.assigned', { count: selected.length }) }}</summary>
        <label v-if="projects.length > 8" class="field" for="administrator-project-search"
          >{{ t('integrations.searchProjects')
          }}<input
            id="administrator-project-search"
            v-model="search"
            type="search"
            :disabled="busy"
        /></label>
        <fieldset class="management-choices" :disabled="busy">
          <legend class="sr-only">{{ t('team.projects') }}</legend>
          <label v-for="project in visible" :key="project.id"
            ><input v-model="selected" type="checkbox" :value="project.id" /><span>{{
              project.name
            }}</span></label
          >
        </fieldset>
        <p v-if="!selected.length" class="small muted">{{ t('administrators.noProjects') }}</p>
      </details>
      <details class="management-section administrator-invitations">
        <summary>{{ t('team.invitations') }}</summary>
        <template v-if="kind === 'create'"
          ><label class="field management-short" for="administrator-delivery"
            >{{ t('adminEditor.delivery')
            }}<select id="administrator-delivery" v-model="delivery" :disabled="busy">
              <option value="email">{{ t('administrators.sendEmail') }}</option>
              <option value="link">{{ t('adminEditor.getLink') }}</option>
              <option value="later">{{ t('adminEditor.later') }}</option>
            </select></label
          >
          <p class="small muted">{{ t('administrators.inviteHint') }}</p></template
        >
        <template v-else-if="kind === 'invitation'"
          ><p>
            {{ t(record?.status === 'draft' ? 'administrators.draft' : 'team.' + record?.status) }}
          </p>
          <div class="management-inline-actions">
            <button type="button" class="button secondary" :disabled="busy" @click="emit('invite')">
              {{ t(record?.status === 'draft' ? 'team.send' : 'team.resend') }}</button
            ><button type="button" class="button secondary" :disabled="busy" @click="emit('link')">
              {{ t('adminEditor.getLink') }}
            </button>
          </div>
          <p class="small muted">{{ t('adminEditor.linkHint') }}</p></template
        >
        <p v-else class="small muted">{{ t('adminEditor.joined') }}</p>
      </details>
      <AgreementStatus :agreement="record?.agreement" expanded />
      <div class="management-security">
        <strong>{{ t('account.security') }}</strong>
        <p v-if="kind === 'member'" class="small muted">
          {{ t('security.authenticator') }}:
          {{ t(record?.security?.totp ? 'security.configured' : 'security.notConfigured') }} ·
          {{ t('security.passkeys') }}:
          {{ t(record?.security?.passkey ? 'security.configured' : 'security.notConfigured') }}
        </p>
        <p class="small muted">{{ t('adminEditor.securityHint') }}</p>
      </div>
    </form>
    <template #footer
      ><button
        v-if="kind !== 'create'"
        type="button"
        class="text-button danger management-delete"
        :disabled="busy"
        @click="emit('remove')"
      >
        {{ t('adminEditor.delete') }}</button
      ><button type="button" class="button secondary" :disabled="busy" @click="emit('close')">
        {{ t('account.cancel') }}</button
      ><LoadingButton
        type="submit"
        form="administrator-editor"
        class="button primary"
        :busy="busy"
        :disabled="busy"
        >{{ t('account.save') }}</LoadingButton
      ></template
    >
  </BaseDialog>
</template>
