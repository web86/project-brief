<script setup>
import { toast } from '../services/toast.js'
import LoadingButton from './LoadingButton.vue'
import { t, formatApiError } from '../i18n/index.js'

import { ref } from 'vue'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import { formatDate } from '../constants/project.js'
const store = useProjectManagementStore()
const adding = ref(false)
const editing = ref('')
const form = ref({ name: '', email: '' })
const secrets = ref({})
const pending = ref(false)
const generatingId = ref('')
const error = ref('')
function notify(message, type = 'success') {
  toast[type](() => formatApiError(message))
}
const activeLinks = (client) => client.accessLinks.filter((link) => link.active)
function edit(client = null) {
  adding.value = !client
  editing.value = client?.id || ''
  form.value = { name: client?.name || '', email: client?.email || '' }
}
async function action(operation) {
  if (pending.value) return
  pending.value = true
  error.value = ''
  try {
    await operation()
  } catch (cause) {
    error.value = cause
  } finally {
    pending.value = false
  }
}
function save(client = null) {
  action(async () => {
    await store.mutateClient(client?.id, client ? 'PATCH' : 'POST', form.value)
    adding.value = false
    editing.value = ''
    notify(
      client
        ? { messageKey: 'ui.clientDetailsSaved', params: {} }
        : { messageKey: 'ui.clientAddedYouCanNowCreateAPersonal', params: {} },
    )
  })
}
function toggle(client) {
  action(async () => {
    await store.mutateClient(client.id, 'PATCH', { active: !client.active })
    delete secrets.value[client.id]
    notify(
      client.active
        ? { messageKey: 'ui.clientDisabledAndTheirLinksRevoked', params: {} }
        : { messageKey: 'ui.clientEnabledCreateANewLinkToGive', params: {} },
    )
  })
}
async function generate(client) {
  generatingId.value = client.id
  await action(async () => {
    delete secrets.value[client.id]
    secrets.value[client.id] = await store.createAccessLink(client.id)
    notify({ messageKey: 'ui.linkForCreated', params: { arg0: client.name } })
  })
  generatingId.value = ''
}
function revoke(client) {
  action(async () => {
    await store.revokeAccess(client.id)
    delete secrets.value[client.id]
    notify({ messageKey: 'ui.accessForHasBeenRevoked', params: { arg0: client.name } })
  })
}
async function copy(client) {
  try {
    await navigator.clipboard.writeText(secrets.value[client.id])
    notify({ messageKey: 'ui.linkCopied', params: {} })
  } catch {
    notify({ messageKey: 'ui.selectTheLinkAndCopyItManually', params: {} }, 'warning')
  }
}
</script>
<template>
  <section id="client-access" class="surface management-panel">
    <div class="management-heading">
      <h2>{{ t('ui.clients') }}</h2>
      <button class="button secondary" :disabled="pending" @click="edit()">
        {{ t('ui.addClient') }}
      </button>
    </div>
    <p class="muted">{{ t('ui.eachPersonOpensTheProjectUsingTheirOwn') }}</p>
    <form v-if="adding" class="client-edit" @submit.prevent="save()">
      <div class="field">
        <label for="new-client-name">{{ t('ui.name') }}</label
        ><input
          @invalid="$event.target.setCustomValidity(t('flow.fieldError'))"
          @input="$event.target.setCustomValidity('')"
          id="new-client-name"
          v-model="form.name"
          required
          maxlength="255"
          autocomplete="name"
        />
      </div>
      <div class="field">
        <label for="new-client-email">{{ t('common.email') }}</label
        ><input
          @invalid="$event.target.setCustomValidity(t('flow.fieldError'))"
          @input="$event.target.setCustomValidity('')"
          id="new-client-email"
          v-model="form.email"
          type="email"
          maxlength="255"
          autocomplete="email"
        />
      </div>
      <div class="order-actions">
        <LoadingButton type="submit" :busy="pending" class="button primary" :disabled="pending">{{
          t('ui.add')
        }}</LoadingButton
        ><button type="button" class="button text" @click="adding = false">
          {{ t('ui.cancel') }}
        </button>
      </div>
    </form>
    <p v-if="!store.clients.length && !adding" class="muted">
      {{ t('ui.addAClientToGiveThemAccessTo') }}
    </p>
    <article
      v-for="client in store.clients"
      :key="client.id"
      class="managed-client"
      :aria-label="client.name"
    >
      <form v-if="editing === client.id" class="client-edit" @submit.prevent="save(client)">
        <div class="field">
          <label :for="`client-name-${client.id}`">{{ t('ui.name') }}</label
          ><input
            @invalid="$event.target.setCustomValidity(t('flow.fieldError'))"
            @input="$event.target.setCustomValidity('')"
            :id="`client-name-${client.id}`"
            v-model="form.name"
            required
            maxlength="255"
          />
        </div>
        <div class="field">
          <label :for="`client-email-${client.id}`">{{ t('common.email') }}</label
          ><input
            @invalid="$event.target.setCustomValidity(t('flow.fieldError'))"
            @input="$event.target.setCustomValidity('')"
            :id="`client-email-${client.id}`"
            v-model="form.email"
            type="email"
            maxlength="255"
          />
        </div>
        <div class="order-actions">
          <LoadingButton type="submit" :busy="pending" class="button primary" :disabled="pending">{{
            t('ui.save')
          }}</LoadingButton
          ><button type="button" class="button text" @click="editing = ''">
            {{ t('ui.cancel') }}
          </button>
        </div>
      </form>
      <template v-else>
        <div class="client-heading">
          <h3>{{ client.name }}</h3>
          <span class="client-state" :class="{ inactive: !client.active }">{{
            client.active ? t('ui.active') : t('ui.disabled')
          }}</span>
        </div>
        <p v-if="client.email" class="client-email muted">{{ client.email }}</p>
        <p class="small">
          {{ t('ui.access') }}
          {{ activeLinks(client).length ? t('ui.linkActive') : t('ui.noActiveLink') }}
        </p>
        <p class="small muted">
          {{ t('ui.lastVisit') }}
          {{ client.lastUsedAt ? formatDate(client.lastUsedAt) : t('ui.notYet') }}
        </p>
        <div v-if="secrets[client.id]" class="client-secret">
          <label :for="`link-${client.id}`">{{ t('ui.linkFor') }} {{ client.name }}</label>
          <input
            @invalid="$event.target.setCustomValidity(t('flow.fieldError'))"
            @input="$event.target.setCustomValidity('')"
            :id="`link-${client.id}`"
            :value="secrets[client.id]"
            readonly
            @focus="$event.target.select()"
          />
          <button class="button secondary" @click="copy(client)">{{ t('ui.copyLink') }}</button>
          <p class="field-help">{{ t('ui.saveTheLinkNowItWillNotBe') }}</p>
        </div>
        <div class="order-actions">
          <LoadingButton
            :busy="pending && generatingId === client.id"
            class="button primary"
            :disabled="pending || !client.active || store.project.status !== 'active'"
            @click="generate(client)"
          >
            {{ activeLinks(client).length ? t('ui.createNewLink') : t('ui.createLink') }}
          </LoadingButton>
          <button
            v-if="activeLinks(client).length"
            class="button secondary"
            :disabled="pending"
            @click="revoke(client)"
          >
            {{ t('ui.revokeLink') }}
          </button>
          <button class="button text" :disabled="pending" @click="edit(client)">
            {{ t('ui.edit') }}
          </button>
          <button class="button text" :disabled="pending" @click="toggle(client)">
            {{ client.active ? t('ui.disable') : t('ui.enable') }}
          </button>
        </div>
        <p v-if="activeLinks(client).length" class="field-help">
          {{ t('flow.linkReplaces', { name: client.name }) }}
        </p>
      </template>
    </article>
    <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
  </section>
</template>
