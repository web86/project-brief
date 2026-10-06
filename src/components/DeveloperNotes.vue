<script setup>
import { t } from '../i18n/index.js'

import { useProjectStore } from '../stores/project'
defineProps({ task: { type: Object, required: true } })
const store = useProjectStore()
</script>

<template>
  <section class="surface developer-notes">
    <div class="notes-heading">
      <h2>
        <label for="developer-notes">{{ t('ui.technicalNotes') }}</label>
      </h2>
      <span id="notes-visibility">{{ t('ui.onlyVisibleToYou') }}</span>
    </div>
    <textarea
      id="developer-notes"
      :value="task.developerNotes"
      :disabled="store.storageBlocked"
      aria-describedby="notes-visibility"
      rows="5"
      maxlength="10000"
      :placeholder="t('ui.componentsImplementationDetailsThingsToConsider')"
      @input="store.updateDeveloperData(task.id, { developerNotes: $event.target.value })"
      @blur="store.apiMode && store.flushDeveloperData()"
    />
  </section>
</template>
