<script setup>
import { computed, ref } from 'vue'
import { formatDate } from '../constants/project'
const props = defineProps({ history: Array, developer: Boolean })
const expanded = ref(false)
const visible = computed(() => [...props.history].filter((event) => props.developer || event.type !== 'developer').reverse())
const events = computed(() => expanded.value ? visible.value : visible.value.slice(0, 5))
</script>
<template>
  <section class="surface history-panel"><h2>История</h2><ol class="timeline"><li v-for="event in events" :key="event.id || `${event.type}-${event.createdAt}`"><time :datetime="event.createdAt">{{ formatDate(event.createdAt) }}</time><p>{{ event.text }}</p></li></ol><button v-if="visible.length > 5" class="text-button history-more" @click="expanded = !expanded">{{ expanded ? 'Свернуть историю' : `Показать всю историю (${visible.length})` }}</button></section>
</template>
