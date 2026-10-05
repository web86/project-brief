<script setup>
import { useProjectStore } from '../stores/project'
defineProps({ task: { type: Object, required: true } })
const store = useProjectStore()
</script>

<template>
  <section class="surface developer-notes">
    <div class="notes-heading">
      <h2><label for="developer-notes">Технические заметки</label></h2>
      <span id="notes-visibility">Видно только вам</span>
    </div>
    <textarea
      id="developer-notes"
      :value="task.developerNotes"
      :disabled="store.storageBlocked"
      aria-describedby="notes-visibility"
      rows="5"
      maxlength="10000"
      placeholder="Компоненты, детали реализации, что стоит учесть…"
      @input="store.updateDeveloperData(task.id, { developerNotes: $event.target.value })"
    />
  </section>
</template>
