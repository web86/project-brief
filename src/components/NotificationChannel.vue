<script setup>
import AppIcon from './AppIcon.vue'
defineProps({ title: String, description: String, icon: String, status: String, featured: Boolean })
</script>
<template>
  <section
    class="surface notification-channel channel-card"
    :class="{ 'channel-featured': featured }"
  >
    <header class="channel-heading">
      <span class="channel-icon"><AppIcon :name="icon" :size="23" /></span>
      <div class="channel-heading-copy">
        <h2>{{ title }}</h2>
        <p>{{ description }}</p>
      </div>
      <span v-if="status" class="channel-status" role="status">{{ status }}</span>
    </header>
    <div class="channel-content"><slot /></div>
  </section>
</template>
