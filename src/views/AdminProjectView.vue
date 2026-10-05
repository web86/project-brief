<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api/client'
import ProjectFields from '../components/ProjectFields.vue'
const route = useRoute()
const project = ref(null)
const form = ref({})
const secretUrl = ref('')
const message = ref('')
const error = ref('')
const errors = ref({})
const pending = ref(false)
const loading = ref(false)
const activeLink = computed(() => project.value?.accessLinks?.find((link) => link.active))
const latestLink = computed(() => project.value?.accessLinks?.at(-1))
const date = (value) =>
  value
    ? new Intl.DateTimeFormat('ru', { dateStyle: 'medium', timeStyle: 'short' }).format(
        new Date(value),
      )
    : 'Ещё не было'
async function load() {
  loading.value = true
  try {
    project.value = (await api.request(`/api/admin/projects/${route.params.uuid}`)).data
    form.value = {
      title: project.value.name,
      website: project.value.website,
      clientName: project.value.clientName || '',
      clientEmail: project.value.clientEmail || '',
      currency: project.value.currency,
      status: project.value.status,
    }
  } catch (cause) {
    error.value = cause.message
  } finally {
    loading.value = false
  }
}
watch(
  () => route.params.uuid,
  () => {
    secretUrl.value = ''
    message.value = ''
    error.value = ''
    load()
  },
  { immediate: true },
)
async function action(operation) {
  if (pending.value) return
  pending.value = true
  error.value = ''
  errors.value = {}
  message.value = ''
  try {
    await operation()
  } catch (cause) {
    error.value = cause.message
    errors.value = cause.errors || {}
  } finally {
    pending.value = false
  }
}
function save() {
  action(async () => {
    await api.request(`/api/admin/projects/${route.params.uuid}`, {
      method: 'PATCH',
      body: form.value,
    })
    await load()
    message.value = 'Настройки сохранены.'
  })
}
function generate() {
  action(async () => {
    secretUrl.value = ''
    const result = await api.request(`/api/admin/projects/${route.params.uuid}/access-links`, {
      method: 'POST',
    })
    secretUrl.value = result.url
    await load()
    message.value = 'Ссылка создана.'
  })
}
function revoke() {
  action(async () => {
    await api.request(
      `/api/admin/projects/${route.params.uuid}/access-links/${activeLink.value.id}`,
      { method: 'DELETE' },
    )
    secretUrl.value = ''
    await load()
    message.value = 'Доступ клиента отозван.'
  })
}
async function copy() {
  try {
    await navigator.clipboard.writeText(secretUrl.value)
    message.value = 'Ссылка скопирована.'
  } catch {
    message.value = 'Выделите ссылку и скопируйте её вручную.'
  }
}
</script>
<template>
  <div>
    <RouterLink to="/admin" class="back-link">← Все проекты</RouterLink>
    <p v-if="loading && !project" role="status">Открываем проект…</p>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <template v-if="project"
      ><div class="admin-page-heading">
        <div>
          <h1>{{ project.name }}</h1>
          <p class="muted">{{ project.doneCount }} из {{ project.tasksCount }} идей готово</p>
        </div>
        <RouterLink :to="`/admin/projects/${project.id}/brief`" class="button primary"
          >Открыть идеи</RouterLink
        >
      </div>
      <div class="admin-settings-layout">
        <section id="client-access" class="surface access-panel">
          <h2>Доступ клиента</h2>
          <p class="muted">Клиент открывает проект по личной ссылке — без регистрации.</p>
          <template v-if="secretUrl"
            ><label for="client-link">Ссылка для клиента</label
            ><input
              id="client-link"
              :value="secretUrl"
              readonly
              @focus="$event.target.select()"
            /><button class="button secondary" @click="copy">Скопировать ссылку</button>
            <p class="field-help">
              Сохраните или отправьте ссылку клиенту сейчас. В целях безопасности она не хранится в
              открытом виде.
            </p></template
          >
          <p v-else-if="activeLink">
            Активная ссылка существует. Чтобы получить новую копируемую ссылку, пересоздайте её.
          </p>
          <p v-else>Активной ссылки пока нет.</p>
          <div v-if="latestLink" class="access-status">
            <strong>{{
              activeLink
                ? 'Активна'
                : latestLink.revokedAt
                  ? 'Доступ отозван'
                  : project.status !== 'active'
                    ? 'Проект неактивен'
                    : 'Срок действия истёк'
            }}</strong>
            <p class="small muted">Последний вход: {{ date(latestLink.lastUsedAt) }}</p>
          </div>
          <div class="admin-project-actions">
            <button
              class="button primary"
              :disabled="pending || project.status !== 'active'"
              @click="generate"
            >
              {{ activeLink ? 'Пересоздать ссылку' : 'Создать ссылку для клиента' }}</button
            ><button v-if="activeLink" class="button secondary" :disabled="pending" @click="revoke">
              Отозвать доступ
            </button>
          </div>
          <p v-if="activeLink" class="field-help">
            Новая ссылка заменит прежнюю и завершит текущий доступ клиента.
          </p>
        </section>
        <form class="surface admin-form" @submit.prevent="save">
          <h2>Настройки проекта</h2>
          <ProjectFields v-model="form" :errors="errors" />
          <div class="field">
            <label for="project-status">Доступность проекта</label
            ><select id="project-status" v-model="form.status">
              <option value="active">Активен</option>
              <option value="inactive">Неактивен</option>
            </select>
          </div>
          <button class="button primary" :disabled="pending">Сохранить настройки</button>
        </form>
      </div>
      <p v-if="message" class="admin-feedback" role="status">{{ message }}</p></template
    >
  </div>
</template>
