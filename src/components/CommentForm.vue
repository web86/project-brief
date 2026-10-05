<script setup>
import { ref, watch } from 'vue'
import { useProjectStore } from '../stores/project'
import AppIcon from './AppIcon.vue'
const props = defineProps({ taskId: String })
const store = useProjectStore()
const text = ref('')
const sent = ref(false)
watch(
  () => props.taskId,
  () => {
    text.value = ''
    sent.value = false
  },
)
watch(
  text,
  () => {
    sent.value = false
  },
  { flush: 'sync' },
)
function submit() {
  if (store.addComment(props.taskId, text.value)) {
    text.value = ''
    sent.value = true
  }
}
</script>
<template>
  <form class="comment-form" @submit.prevent="submit">
    <label for="comment-text">Написать {{ store.isDeveloper ? 'клиенту' : 'разработчику' }}</label
    ><textarea
      id="comment-text"
      v-model="text"
      rows="2"
      maxlength="5000"
      placeholder="Задайте вопрос или добавьте подробности…"
    />
    <div class="comment-form-footer">
      <span class="muted small" aria-live="polite">{{ sent ? 'Сообщение добавлено' : '' }}</span
      ><button
        type="submit"
        class="button primary"
        :disabled="!text.trim() || store.storageBlocked"
      >
        <AppIcon name="send" :size="16" />Отправить
      </button>
    </div>
  </form>
</template>
