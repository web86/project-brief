<script setup>
import { t } from '../i18n/index.js'

import AppIcon from './AppIcon.vue'
import LoadingButton from './LoadingButton.vue'
import { useProjectStore } from '../stores/project'
defineProps({ task: { type: Object, required: true } })
const store = useProjectStore()
</script>

<template>
  <section class="surface developer-notes">
    <details :key="task.id" class="notes-disclosure">
      <summary class="notes-heading">
        <span class="notes-title"
          ><AppIcon name="code" :size="18" />{{ t('ui.technicalNotes') }}</span
        ><AppIcon name="chevron" class="notes-chevron" :size="16" />
      </summary>
      <div class="notes-body">
        <p id="notes-visibility" class="small muted">{{ t('ui.onlyVisibleToYou') }}</p>
        <label class="sr-only" for="developer-notes">{{ t('ui.technicalNotes') }}</label>
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
      </div>
    </details>
    <div
      v-if="store.hasDeveloperChanges(task.id) || store.savingDeveloper"
      class="developer-save-actions"
    >
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
