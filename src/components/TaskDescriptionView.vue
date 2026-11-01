<script setup>
import { computed } from 'vue'
import AgreementDocument from './AgreementDocument.vue'
import { descriptionBlocks } from '../utils/taskRichText.js'
const props = defineProps({ task: Object })
const blocks = computed(() => descriptionBlocks(props.task.descriptionHtml, props.task.description))
</script>
<template>
  <AgreementDocument v-if="task.descriptionHtml" :blocks="blocks" />
  <p v-else class="prose">{{ task.description }}</p>
</template>
