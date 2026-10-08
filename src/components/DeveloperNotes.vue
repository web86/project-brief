<script setup>
import { t } from '../i18n/index.js'

import LoadingButton from './LoadingButton.vue'
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
    />
    <div class="developer-save-actions">
      <p v-if="store.hasDeveloperChanges(task.id)" role="status">
        ● {{ t('developerEditor.dirty') }}
      </p>
      <LoadingButton
        class="button primary"
        :busy="store.savingDeveloper"
        :disabled="
          !store.hasDeveloperChanges(task.id) || store.savingDeveloper || store.storageBlocked
        "
        @click="store.saveDeveloperData(task.id)"
      >
        {{ t('developerEditor.save') }}
      </LoadingButton>
    </div>
  </section>
</template>
