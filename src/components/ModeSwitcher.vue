<script setup>
import { useProjectStore } from '../stores/project'
import AppIcon from './AppIcon.vue'
const store = useProjectStore()
</script>

<template>
  <div class="mode-switch" role="group" aria-label="Режим просмотра">
    <button :class="{ active: !store.isDeveloper }" :aria-pressed="!store.isDeveloper" @click="store.setMode('client')"><AppIcon name="user" :size="15" /><span>Режим клиента</span></button>
    <button :class="{ active: store.isDeveloper }" :aria-pressed="store.isDeveloper" @click="store.setMode('developer')"><AppIcon name="code" :size="16" /><span>Режим разработчика</span></button>
  </div>
</template>
