<script setup>
import { ref } from 'vue'
import { useProjectManagementStore } from '../stores/projectManagement.js'
import { formatDate } from '../constants/project.js'
const store = useProjectManagementStore()
const adding = ref(false)
const editing = ref('')
const form = ref({ name: '', email: '' })
const secrets = ref({})
const pending = ref(false)
const error = ref('')
const message = ref('')
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
  message.value = ''
  try {
    await operation()
  } catch (cause) {
    error.value =
      Object.values(cause.errors || {})
        .flat()
        .join(' ') || cause.message
  } finally {
    pending.value = false
  }
}
function save(client = null) {
  action(async () => {
    await store.mutateClient(client?.id, client ? 'PATCH' : 'POST', form.value)
    adding.value = false
    editing.value = ''
    message.value = client
      ? 'Данные клиента сохранены.'
      : 'Клиент добавлен. Теперь можно создать личную ссылку.'
  })
}
function toggle(client) {
  action(async () => {
    await store.mutateClient(client.id, 'PATCH', { active: !client.active })
    delete secrets.value[client.id]
    message.value = client.active
      ? 'Клиент отключён, его ссылки отозваны.'
      : 'Клиент включён. Для доступа создайте новую ссылку.'
  })
}
function generate(client) {
  action(async () => {
    delete secrets.value[client.id]
    secrets.value[client.id] = await store.createAccessLink(client.id)
    message.value = `Ссылка для ${client.name} создана.`
  })
}
function revoke(client) {
  action(async () => {
    await store.revokeAccess(client.id)
    delete secrets.value[client.id]
    message.value = `Доступ по ссылке для ${client.name} отозван.`
  })
}
async function copy(client) {
  try {
    await navigator.clipboard.writeText(secrets.value[client.id])
    message.value = 'Ссылка скопирована.'
  } catch {
    message.value = 'Выделите ссылку и скопируйте её вручную.'
  }
}
</script>
<template>
  <section id="client-access" class="surface management-panel">
    <div class="management-heading">
      <h2>Клиенты</h2>
      <button class="button secondary" :disabled="pending" @click="edit()">
        + Добавить клиента
      </button>
    </div>
    <p class="muted">Каждый человек открывает проект по своей личной ссылке.</p>
    <form v-if="adding" class="client-edit" @submit.prevent="save()">
      <div class="field">
        <label for="new-client-name">Имя *</label
        ><input
          id="new-client-name"
          v-model="form.name"
          required
          maxlength="255"
          autocomplete="name"
        />
      </div>
      <div class="field">
        <label for="new-client-email">Email</label
        ><input
          id="new-client-email"
          v-model="form.email"
          type="email"
          maxlength="255"
          autocomplete="email"
        />
      </div>
      <div class="order-actions">
        <button class="button primary" :disabled="pending">Добавить</button
        ><button type="button" class="button text" @click="adding = false">Отмена</button>
      </div>
    </form>
    <p v-if="!store.clients.length && !adding" class="muted">
      Добавьте клиента, чтобы дать ему доступ к проекту.
    </p>
    <article
      v-for="client in store.clients"
      :key="client.id"
      class="managed-client"
      :aria-label="client.name"
    >
      <form v-if="editing === client.id" class="client-edit" @submit.prevent="save(client)">
        <div class="field">
          <label :for="`client-name-${client.id}`">Имя *</label
          ><input :id="`client-name-${client.id}`" v-model="form.name" required maxlength="255" />
        </div>
        <div class="field">
          <label :for="`client-email-${client.id}`">Email</label
          ><input
            :id="`client-email-${client.id}`"
            v-model="form.email"
            type="email"
            maxlength="255"
          />
        </div>
        <div class="order-actions">
          <button class="button primary" :disabled="pending">Сохранить</button
          ><button type="button" class="button text" @click="editing = ''">Отмена</button>
        </div>
      </form>
      <template v-else>
        <div class="client-heading">
          <h3>{{ client.name }}</h3>
          <span class="client-state" :class="{ inactive: !client.active }">{{
            client.active ? 'Активен' : 'Отключён'
          }}</span>
        </div>
        <p v-if="client.email" class="client-email muted">{{ client.email }}</p>
        <p class="small">
          Доступ: {{ activeLinks(client).length ? 'ссылка активна' : 'активной ссылки нет' }}
        </p>
        <p class="small muted">
          Последний вход: {{ client.lastUsedAt ? formatDate(client.lastUsedAt) : 'ещё не было' }}
        </p>
        <div v-if="secrets[client.id]" class="client-secret">
          <label :for="`link-${client.id}`">Ссылка для {{ client.name }}</label>
          <input
            :id="`link-${client.id}`"
            :value="secrets[client.id]"
            readonly
            @focus="$event.target.select()"
          />
          <button class="button secondary" @click="copy(client)">Скопировать ссылку</button>
          <p class="field-help">
            Сохраните ссылку сейчас: после ухода со страницы она не будет показана снова.
          </p>
        </div>
        <div class="order-actions">
          <button
            class="button primary"
            :disabled="pending || !client.active || store.project.status !== 'active'"
            @click="generate(client)"
          >
            {{ activeLinks(client).length ? 'Создать новую ссылку' : 'Создать ссылку' }}
          </button>
          <button
            v-if="activeLinks(client).length"
            class="button secondary"
            :disabled="pending"
            @click="revoke(client)"
          >
            Отозвать ссылку
          </button>
          <button class="button text" :disabled="pending" @click="edit(client)">
            Редактировать
          </button>
          <button class="button text" :disabled="pending" @click="toggle(client)">
            {{ client.active ? 'Отключить' : 'Включить' }}
          </button>
        </div>
        <p v-if="activeLinks(client).length" class="field-help">
          Новая ссылка заменит прежнюю только для {{ client.name }}.
        </p>
      </template>
    </article>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <p v-if="message" class="admin-feedback" role="status">{{ message }}</p>
  </section>
</template>
