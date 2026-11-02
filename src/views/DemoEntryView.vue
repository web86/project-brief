<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api/client.js'
import { demo, STATIC_DEMO } from '../services/demo.js'
import { setManualLocale } from '../i18n/index.js'
const route = useRoute(),
  busy = ref(false),
  error = ref('')
async function enter(role) {
  busy.value = true
  error.value = ''
  try {
    setManualLocale('en')
    const result = await api.request('/api/demo/enter/' + role)
    window.location.assign(result.destination)
  } catch {
    error.value = STATIC_DEMO
      ? 'This demo is temporarily unavailable. Please try again later.'
      : 'The demo is unavailable. Please restore the local dataset and try again.'
    busy.value = false
  }
}
onMounted(() => {
  if (demo.readOnly && route.params.role) enter(route.params.role)
})
</script>
<template>
  <section class="demo-entry">
    <template v-if="demo.readOnly">
      <span class="demo-eyebrow">PROJECT BRIEF · INTERACTIVE DEMO</span>
      <h1>A studio. Ten projects.<br />Every detail in view.</h1>
      <p>
        Explore Harborline Digital Studio, a fictional team bringing thoughtful digital experiences
        to life.
      </p>
      <div class="demo-entry-options">
        <article class="surface">
          <span class="demo-eyebrow">THE STUDIO EXPERIENCE</span>
          <h2>Developer Demo</h2>
          <p>Projects, people, delivery and the tools that keep a studio moving.</p>
          <button class="button primary" :disabled="busy" @click="enter('developer')">
            Explore the studio →
          </button>
        </article>
        <article class="surface">
          <span class="demo-eyebrow">THE CLIENT EXPERIENCE</span>
          <h2>Client Demo</h2>
          <p>A clear view of progress, decisions and conversations for Northstar Commerce.</p>
          <button class="button secondary" :disabled="busy" @click="enter('client')">
            Open the client portal →
          </button>
        </article>
      </div>
      <p class="small muted">Demo · View only. All people, companies and activity are fictional.</p>
      <p v-if="error" role="alert">{{ error }}</p>
    </template>
    <h1 v-else>Demo is unavailable</h1>
  </section>
</template>
<style scoped>
.demo-entry {
  max-width: 1000px;
  margin: 5vh auto;
  padding: 32px 20px;
}
.demo-eyebrow {
  color: var(--action);
  font-size: 11px;
  letter-spacing: 0.12em;
  font-weight: 700;
}
.demo-entry h1 {
  font-size: clamp(32px, 5vw, 56px);
  line-height: 1.12;
  margin: 20px 0;
  letter-spacing: -0.035em;
}
.demo-entry > p {
  max-width: 620px;
  color: var(--text-subtle);
  line-height: 1.7;
}
.demo-entry-options {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
  margin: 36px 0;
}
.demo-entry-options article {
  padding: 28px;
}
.demo-entry-options p {
  min-height: 60px;
  color: var(--text-subtle);
}
@media (max-width: 640px) {
  .demo-entry-options {
    grid-template-columns: 1fr;
  }
}
</style>
