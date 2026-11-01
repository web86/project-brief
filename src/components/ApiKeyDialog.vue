<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import BaseDialog from './BaseDialog.vue'
import LoadingButton from './LoadingButton.vue'
import { api } from '../api/client.js'
import { t, formatApiError } from '../i18n/index.js'
import { isSecurityCancellation } from '../services/stepUp.js'
const props = defineProps({
  open: Boolean,
  context: Object,
  initialType: { type: String, default: 'integration' },
  agentOnly: Boolean,
})
const emit = defineEmits(['close', 'created'])
const state = ref(null),
  type = ref('integration'),
  name = ref(''),
  scopes = ref([]),
  expires = ref(''),
  sourceId = ref(''),
  sourceName = ref(''),
  busy = ref(false),
  error = ref(''),
  secret = ref(''),
  copied = ref(false)
let revision = 0
const available = computed(() =>
  type.value === 'agent' ? state.value?.agentScopes || [] : state.value?.scopes || [],
)
const requiresSource = computed(() =>
  scopes.value.some((scope) => ['clients:sso', 'requests:create'].includes(scope)),
)
const usesSource = computed(
  () => requiresSource.value || scopes.value.includes('projects:provision'),
)
const label = (scope) =>
  scope.startsWith('agent:')
    ? t('collaboration.scope.' + scope.replaceAll(':', '_'))
    : t('integrations.scope_' + scope.replace(':', '_'))
watch(type, () => {
  scopes.value = []
  sourceId.value = ''
  sourceName.value = ''
})
watch(
  () => props.open,
  async (open) => {
    const current = ++revision
    if (!open) {
      secret.value = ''
      return
    }
    type.value = props.agentOnly ? 'agent' : props.initialType
    name.value = ''
    scopes.value = []
    expires.value = ''
    sourceId.value = ''
    sourceName.value = ''
    secret.value = ''
    copied.value = false
    error.value = ''
    busy.value = true
    try {
      const result = props.context || (await api.request('/api/admin/integrations'))
      if (current === revision) state.value = result
    } catch (cause) {
      if (current === revision && !isSecurityCancellation(cause))
        error.value = formatApiError(cause)
    } finally {
      if (current === revision) busy.value = false
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  revision++
  secret.value = ''
})
async function create() {
  if (busy.value || !scopes.value.length) return
  const current = revision
  busy.value = true
  error.value = ''
  try {
    const result = await api.request('/api/admin/integrations/tokens', {
      method: 'POST',
      body: {
        type: type.value,
        name: name.value,
        scopes: scopes.value,
        expiresAt: expires.value ? expires.value + 'T23:59:59Z' : null,
        ...(usesSource.value
          ? sourceId.value
            ? { sourceId: sourceId.value }
            : { sourceName: sourceName.value }
          : {}),
      },
    })
    if (current === revision) {
      secret.value = result.secret
      emit('created', {
        id: result.id,
        type: type.value,
        name: name.value,
        scopes: [...scopes.value],
      })
    }
  } catch (cause) {
    if (current === revision && !isSecurityCancellation(cause)) error.value = formatApiError(cause)
  } finally {
    if (current === revision) busy.value = false
  }
}
async function copy() {
  const current = revision
  try {
    await navigator.clipboard.writeText(secret.value)
    if (current === revision) copied.value = true
  } catch (cause) {
    if (current === revision) error.value = formatApiError(cause)
  }
}
</script>
<template>
  <BaseDialog
    :open="open"
    :title="t(secret ? 'integrations.once' : 'agentUx.createApi')"
    id="api-key-dialog"
    class="management-dialog integration-editor-dialog"
    @close="!busy && emit('close')"
  >
    <div v-if="secret" class="management-body">
      <p>{{ t('integrations.onceHint') }}</p>
      <code class="api-key-reveal">{{ secret }}</code
      ><button class="button secondary" @click="copy">
        {{ t(copied ? 'integrations.copied' : 'integrations.copy') }}
      </button>
    </div>
    <form
      v-else
      id="api-key-create-form"
      class="management-body api-key-fields"
      @submit.prevent="create"
    >
      <p v-if="error" class="field-error" role="alert">{{ error }}</p>
      <label class="field" for="token-name"
        >{{ t('integrations.name')
        }}<input id="token-name" v-model="name" required maxlength="100" :disabled="busy"
      /></label>
      <label class="field" for="token-type"
        >{{ t('collaboration.keyType')
        }}<select id="token-type" v-model="type" :disabled="busy || agentOnly">
          <option v-if="!agentOnly" value="integration">
            {{ t('collaboration.integration') }}
          </option>
          <option value="agent">{{ t('agentUx.agents') }}</option>
        </select></label
      >
      <p v-if="type === 'agent'" class="small shared-key-warning" role="note">
        {{ t('agentUx.sharedTrust') }}
      </p>
      <fieldset class="api-key-scopes">
        <legend>{{ t('integrations.scopes') }}</legend>
        <label v-for="scope in available" :key="scope"
          ><input v-model="scopes" type="checkbox" :value="scope" :disabled="busy" /><span
            >{{ label(scope)
            }}<small>{{
              scope.startsWith('agent:')
                ? t('collaboration.scopeHint.' + scope.replaceAll(':', '_'))
                : t('collaboration.scopeBoundary')
            }}</small
            ><code>{{ scope }}</code></span
          ></label
        >
      </fieldset>
      <p
        v-if="
          scopes.some((scope) =>
            [
              'agent:team-chat:read',
              'agent:discussions:read',
              'agent:attachments:read',
              'comments:read',
              'attachments:read',
            ].includes(scope),
          )
        "
        class="small muted"
      >
        {{ t('integrations.sensitiveReadHint') }}
      </p>
      <div v-if="usesSource" class="api-key-fields">
        <label class="field" for="token-source"
          >{{ t('requests.sourceLabel')
          }}<select id="token-source" v-model="sourceId" :disabled="busy">
            <option value="">{{ t('requests.newSource') }}</option>
            <option v-for="source in state?.sources || []" :key="source.id" :value="source.id">
              {{ source.name }}
            </option>
          </select></label
        ><label v-if="!sourceId" class="field" for="token-source-name"
          >{{ t('requests.sourceName')
          }}<input
            id="token-source-name"
            v-model="sourceName"
            :required="requiresSource"
            :disabled="busy"
            maxlength="100"
        /></label>
        <p class="small muted">{{ t('requests.sourceHint') }}</p>
      </div>
      <label class="field" for="token-expires"
        >{{ t('integrations.expires')
        }}<input id="token-expires" v-model="expires" type="date" :disabled="busy"
      /></label>
    </form>
    <template #footer
      ><button class="button secondary" :disabled="busy" @click="emit('close')">
        {{ t(secret ? 'integrations.close' : 'ui.cancel') }}</button
      ><LoadingButton
        v-if="!secret"
        form="api-key-create-form"
        type="submit"
        class="button primary"
        :busy="busy"
        :disabled="busy || !scopes.length || !state"
        >{{ t('integrations.create') }}</LoadingButton
      ></template
    >
  </BaseDialog>
</template>
<style scoped>
.api-key-fields {
  display: grid;
  gap: 16px;
}
.api-key-fields .field {
  margin: 0;
}
.api-key-scopes {
  display: grid;
  gap: 12px;
  min-width: 0;
  padding: 14px;
  border: 1px solid var(--border);
  border-radius: 10px;
}
.api-key-scopes label {
  display: flex;
  align-items: start;
  gap: 10px;
  font-weight: 400;
}
.api-key-scopes input {
  flex: 0 0 16px;
  width: 16px;
  height: 16px;
  margin-top: 4px;
}
.api-key-scopes label > span {
  min-width: 0;
}
.api-key-scopes small,
.api-key-scopes code {
  display: block;
  color: var(--muted);
  font-size: 0.75rem;
  margin-top: 4px;
  overflow-wrap: anywhere;
}
.shared-key-warning {
  padding: 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
}
.api-key-reveal {
  display: block;
  user-select: all;
  overflow-wrap: anywhere;
  padding: 14px;
  border: 1px solid var(--border);
  border-radius: 8px;
  margin-bottom: 16px;
}
</style>
