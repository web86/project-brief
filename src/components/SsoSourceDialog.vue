<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import BaseDialog from './BaseDialog.vue'
import NotificationToggle from './NotificationToggle.vue'
import LoadingButton from './LoadingButton.vue'
import { t, intlLocale } from '../i18n/index.js'
const props = defineProps({
  open: Boolean,
  source: Object,
  projects: { type: Array, default: () => [] },
  tokens: { type: Array, default: () => [] },
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
const emit = defineEmits(['close', 'save', 'remove', 'create-key'])
const name = ref(''),
  origin = ref(''),
  enabled = ref(false),
  selected = ref([]),
  search = ref(''),
  keyName = ref('')
const automatic = computed(() => props.source?.automaticSsoProjectIds || [])
const automaticProjects = computed(() =>
  props.projects.filter((p) => automatic.value.includes(p.id)),
)
const manual = computed(() =>
  props.projects.filter(
    (p) =>
      !automatic.value.includes(p.id) &&
      p.name
        .toLocaleLowerCase(intlLocale())
        .includes(search.value.trim().toLocaleLowerCase(intlLocale())),
  ),
)
const count = computed(() => new Set([...selected.value, ...automatic.value]).size)
watch(
  () => [props.open, props.source?.id],
  () => {
    if (!props.open) return
    name.value = props.source?.name || ''
    origin.value = props.source?.ssoOrigin || ''
    enabled.value = !!props.source?.ssoEnabled
    selected.value = [...(props.source?.ssoProjectIds || [])]
    search.value = ''
    keyName.value = Array.from((props.source?.name || '') + ' SSO')
      .slice(0, 100)
      .join('')
  },
  { immediate: true },
)
</script>
<template>
  <BaseDialog
    :open="open"
    :title="t(source ? 'ssoUx.configure' : 'ssoUx.addSource')"
    id="sso-source-dialog-title"
    class="management-dialog sso-source-dialog"
    @close="emit('close')"
  >
    <form
      id="sso-source-editor"
      class="management-body sso-settings"
      @submit.prevent="
        emit('save', { name, ssoOrigin: origin, ssoEnabled: enabled, projectIds: selected })
      "
    >
      <p v-if="error" ref="errorAlert" role="alert" class="field-error" tabindex="-1">
        {{ error }}
      </p>
      <div class="management-fields">
        <label class="field" for="sso-source-name"
          >{{ t('integrations.name')
          }}<input
            id="sso-source-name"
            v-model="name"
            maxlength="100"
            required
            :disabled="busy" /></label
        ><label class="field" for="sso-origin"
          >{{ t('sso.origin')
          }}<input
            id="sso-origin"
            v-model="origin"
            type="url"
            maxlength="255"
            placeholder="https://portal.example.com"
            required
            :disabled="busy"
        /></label>
      </div>
      <NotificationToggle
        id="sso-enabled"
        :checked="enabled"
        :disabled="busy"
        :label="t('sso.enabled')"
        @change="enabled = $event.target.checked"
      />
      <p class="small muted">{{ t('sso.originHint') }}</p>
      <details class="management-section sso-api-key">
        <summary>
          {{ t('sourceEditor.key') }} ·
          {{ t(tokens.length ? 'ssoUx.keyReady' : 'ssoUx.keyRequired') }}
        </summary>
        <p class="small muted">{{ t('ssoUx.keyHint') }} <code>clients:sso</code></p>
        <template v-if="source"
          ><ul v-if="tokens.length" class="sso-bound-keys">
            <li v-for="token in tokens" :key="token.id">{{ token.name }}</li>
          </ul>
          <p v-if="tokens.length" class="small muted">{{ t('sourceEditor.boundKeys') }}</p>
          <label class="field management-short" for="sso-key-name"
            >{{ t('sourceEditor.keyName')
            }}<input id="sso-key-name" v-model="keyName" maxlength="100" :disabled="busy" /></label
          ><button
            type="button"
            class="button secondary"
            :disabled="busy || !keyName.trim()"
            @click="emit('create-key', keyName)"
          >
            {{ t('ssoUx.createKey') }}
          </button></template
        >
        <p v-else class="small muted">{{ t('sourceEditor.saveFirst') }}</p>
        <p class="small muted">{{ t('integrations.onceHint') }}</p>
      </details>
      <details class="management-section sso-projects">
        <summary>{{ t('ssoUx.available') }} · {{ count }}</summary>
        <div class="sso-automatic">
          <strong>{{ t('ssoUx.automatic', { count: automaticProjects.length }) }}</strong>
          <ul v-if="automaticProjects.length">
            <li v-for="project in automaticProjects" :key="project.id">{{ project.name }}</li>
          </ul>
          <p v-else class="small muted">{{ t('sourceEditor.noAutomatic') }}</p>
        </div>
        <strong>{{
          t('ssoUx.manual', { count: selected.filter((id) => !automatic.includes(id)).length })
        }}</strong>
        <p class="small muted">{{ t('ssoUx.manualHint') }}</p>
        <label class="field" for="sso-project-search"
          >{{ t('integrations.searchProjects')
          }}<input id="sso-project-search" v-model="search" type="search" :disabled="busy"
        /></label>
        <fieldset class="management-choices" :disabled="busy">
          <legend class="sr-only">{{ t('ssoUx.available') }}</legend>
          <label v-for="project in manual" :key="project.id"
            ><input v-model="selected" type="checkbox" :value="project.id" /><span>{{
              project.name
            }}</span></label
          >
        </fieldset>
        <p v-if="!manual.length" class="small muted">{{ t('integrations.noProjectMatches') }}</p>
      </details>
    </form>
    <template #footer
      ><button
        v-if="source"
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
        form="sso-source-editor"
        class="button primary"
        :busy="busy"
        :disabled="busy"
        >{{ t('account.save') }}</LoadingButton
      ></template
    >
  </BaseDialog>
</template>

<style scoped>
.sso-automatic {
  margin-bottom: 18px;
}
.sso-automatic > strong,
.sso-projects > strong {
  display: block;
  margin-bottom: 6px;
}
.sso-projects > .small {
  margin: 0 0 14px;
}
.sso-api-key > p,
.sso-api-key > ul {
  margin: 0 0 12px;
}
.sso-api-key > .field {
  margin: 12px 0;
}
.sso-api-key > .button {
  margin-bottom: 12px;
}
</style>
