<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api/client'
import { useProjectStore } from '../stores/project'
const router = useRouter()
const store = useProjectStore()
const email = ref('')
const password = ref('')
const pending = ref(false)
const error = ref('')
async function login() {
  if (pending.value) return
  pending.value = true
  error.value = ''
  try {
    const result = await api.request('/api/admin/login', {
      method: 'POST',
      body: { email: email.value, password: password.value },
    })
    store.admin = result.user
    store.apiError = ''
    store.sessionLost = false
    password.value = ''
    await router.push('/admin')
  } catch (cause) {
    error.value =
      Object.values(cause.errors || {})
        .flat()
        .join(' ') || cause.message
  } finally {
    pending.value = false
  }
}
</script>
<template>
  <div class="admin-login">
    <div class="page-heading">
      <h1>Вход для администратора</h1>
      <p>Ваши проекты и идеи клиентов в одном месте.</p>
    </div>
    <form class="surface admin-form" @submit.prevent="login">
      <div class="field">
        <label for="admin-email">Email</label
        ><input id="admin-email" v-model="email" type="email" autocomplete="username" required />
      </div>
      <div class="field">
        <label for="admin-password">Пароль</label
        ><input
          id="admin-password"
          v-model="password"
          type="password"
          autocomplete="current-password"
          required
        />
      </div>
      <p v-if="error" class="field-error" role="alert">{{ error }}</p>
      <button class="button primary" type="submit" :disabled="pending">
        {{ pending ? 'Входим…' : 'Войти' }}
      </button>
    </form>
  </div>
</template>
