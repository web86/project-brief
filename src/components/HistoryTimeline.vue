<script setup>
import { t } from '../i18n/index.js'

import { historyText } from '../utils/history.js'
import { computed } from 'vue'
import { formatDate } from '../constants/project'
const props = defineProps({ history: Array, developer: Boolean })
const visible = computed(() =>
  [...props.history].filter((event) => props.developer || event.type !== 'developer').reverse(),
)
</script>
<template>
  <details class="surface history-panel">
    <summary>
      {{ t('ui.changeHistory') }} <span>({{ visible.length }})</span>
    </summary>
    <ol class="timeline">
      <li v-for="event in visible" :key="event.id || `${event.type}-${event.createdAt}`">
        <time :datetime="event.createdAt">{{ formatDate(event.createdAt) }}</time>
        <p>{{ historyText(event) }}</p>
      </li>
    </ol>
  </details>
</template>
