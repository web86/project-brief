<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
const route = useRoute()
const message = computed(
  () =>
    ({
      expired: 'Срок действия ссылки истёк.',
      unavailable: 'Проект недоступен.',
      session: 'Сессия завершена. Откройте ссылку клиента ещё раз.',
    })[route.query.reason] || 'Ссылка недействительна или была отозвана.',
)
</script>
<template>
  <div class="empty-state page-empty">
    <h1>Нет доступа к проекту</h1>
    <p>{{ message }}</p>
    <p>Попросите разработчика прислать новую ссылку.</p>
  </div>
</template>
