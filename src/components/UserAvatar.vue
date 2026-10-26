<script setup>
import { computed, ref, watch } from 'vue'
import { intlLocale } from '../i18n/index.js'
const props = defineProps({ user: { type: Object, required: true } })
const failed = ref(false)
watch(
  () => props.user.avatarUrl,
  () => {
    failed.value = false
  },
)
const initials = computed(() => {
  const words = (props.user.name || '').trim().split(/\s+/).filter(Boolean)
  return (
    [words[0], words.length > 1 ? words.at(-1) : null]
      .filter(Boolean)
      .map((word) => Array.from(word)[0])
      .join('')
      .toLocaleUpperCase(intlLocale()) || '?'
  )
})
</script>
<template>
  <span class="task-account-avatar user-avatar" aria-hidden="true"
    ><img
      v-if="user.avatarUrl && !failed"
      :src="user.avatarUrl"
      alt=""
      referrerpolicy="no-referrer"
      @error="failed = true"
    /><span v-else>{{ initials }}</span></span
  >
</template>
