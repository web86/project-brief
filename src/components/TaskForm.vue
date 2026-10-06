<script setup>
import { t, formatApiError } from '../i18n/index.js'

import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useProjectStore } from '../stores/project'
import { PRIORITIES } from '../constants/project'
import AttachmentList from './AttachmentList.vue'
import { getTaskLocation } from '../constants/project.js'
import { canEditClientTask } from '../utils/clientDraft.js'
import LoadingButton from './LoadingButton.vue'
import FileUploader from './FileUploader.vue'
import LocationInput from './LocationInput.vue'
import AiTaskAssistant from './AiTaskAssistant.vue'
import AppIcon from './AppIcon.vue'
const props = defineProps({ task: Object })
const store = useProjectStore()
const router = useRouter()
const title = ref(props.task?.title || '')
const initialSection = props.task
  ? store.taskSection(props.task)?.name || getTaskLocation(props.task)
  : ''
const location = ref(initialSection)
const detailLocation = ref(
  props.task && getTaskLocation(props.task) !== initialSection ? getTaskLocation(props.task) : '',
)
const description = ref(props.task?.description || '')
const expectedResult = ref(props.task?.expectedResult || '')
const priority = ref(props.task?.priority || 'normal')
const attachments = ref([])
const processingFiles = ref(false)
const submitted = ref(false)
const saving = ref(false)
const error = ref('')
const errors = computed(() => ({
  title: !title.value.trim(),
  location: !location.value.trim(),
  description: !description.value.trim(),
}))
async function submit() {
  submitted.value = true
  error.value = ''
  if (processingFiles.value || saving.value) return
  if (Object.values(errors.value).some(Boolean)) {
    const field = errors.value.title
      ? 'idea-title'
      : errors.value.location
        ? 'idea-location'
        : 'idea-description'
    document.getElementById(field)?.focus()
    return
  }
  saving.value = true
  try {
    const input = {
      title: title.value,
      section: location.value.trim(),
      location: detailLocation.value.trim() || location.value.trim(),
      description: description.value,
      expectedResult: expectedResult.value,
      priority: priority.value,
    }
    if (props.task) {
      if (!store.isDeveloper && !canEditClientTask(store.findTask(props.task.id)))
        throw new Error(t('flow.briefLocked'))
      if (!(await store.updateTask(props.task.id, input)))
        throw new Error(t('ui.weCouldNotCompleteThisActionPleaseTry'))
      if (
        attachments.value.length &&
        store.apiMode &&
        !(await store.uploadAttachments(props.task.id, attachments.value))
      )
        throw new Error(t('flow.fileSaveError'))
      if (!store.apiMode && attachments.value.length)
        store.updateTask(props.task.id, {
          attachments: [...props.task.attachments, ...attachments.value],
        })
      await router.push(store.taskPath(props.task.id))
    } else {
      await store.addTask({ ...input, attachments: attachments.value })
      await router.push(store.projectPath)
    }
  } catch (cause) {
    error.value = cause
  } finally {
    saving.value = false
  }
}
</script>
<template>
  <form class="idea-form" novalidate @submit.prevent="submit">
    <div class="field">
      <label for="idea-title"
        >{{ t('ui.whatWouldYouLikeToChange') }}
        <span class="required-dot" aria-hidden="true">*</span></label
      ><input
        id="idea-title"
        v-model="title"
        :placeholder="t('ui.forExampleMakeProductPhotosLarger')"
        maxlength="160"
        required
        :aria-invalid="submitted && errors.title"
        :aria-describedby="submitted && errors.title ? 'title-error' : undefined"
      />
      <p v-if="submitted && errors.title" id="title-error" class="field-error">
        {{ t('ui.giveYourIdeaAShortTitle') }}
      </p>
    </div>
    <div class="field">
      <label for="idea-location"
        >{{ t('ui.whereIsIt') }} <span class="required-dot" aria-hidden="true">*</span></label
      ><LocationInput
        id="idea-location"
        v-model="location"
        :suggestions="store.locationOptions"
        :invalid="submitted && errors.location"
        :described-by="submitted && errors.location ? 'location-error' : undefined"
      />
      <p v-if="submitted && errors.location" id="location-error" class="field-error">
        {{ t('ui.enterAPageWebsiteElementOrLink') }}
      </p>
    </div>
    <div v-if="task" class="field">
      <label for="idea-location-detail"
        >{{ t('flow.locationDetail') }}
        <span class="optional-label">{{ t('ui.optional') }}</span></label
      >
      <input id="idea-location-detail" v-model="detailLocation" maxlength="2048" />
    </div>
    <div class="field">
      <div class="field-label-row">
        <label for="idea-description"
          >{{ t('ui.tellUsMore') }} <span class="required-dot" aria-hidden="true">*</span></label
        ><AiTaskAssistant :description="description" />
      </div>
      <textarea
        id="idea-description"
        v-model="description"
        :placeholder="t('ui.describeInYourOwnWordsWhatYouDislike')"
        rows="4"
        maxlength="10000"
        required
        :aria-invalid="submitted && errors.description"
        :aria-describedby="submitted && errors.description ? 'description-error' : undefined"
      />
      <p v-if="submitted && errors.description" id="description-error" class="field-error">
        {{ t('ui.addSomeDetailsToHelpUsUnderstandYour') }}
      </p>
    </div>
    <div class="optional-fields">
      <div class="field">
        <label for="idea-result"
          >{{ t('ui.whatResultDoYouExpect') }}
          <span class="optional-label">{{ t('ui.optional') }}</span></label
        ><textarea
          id="idea-result"
          v-model="expectedResult"
          :placeholder="t('ui.forExampleIWantToSwipeThroughPhotos')"
          rows="2"
          maxlength="10000"
        />
      </div>
      <fieldset class="field priority-field">
        <legend>{{ t('ui.howImportantIsIt') }}</legend>
        <div class="priority-options">
          <label
            v-for="(option, key) in PRIORITIES"
            :key="key"
            :class="{ selected: priority === key }"
            ><input v-model="priority" type="radio" name="priority" :value="key" /><span
              class="radio-dot"
            /><span>{{ option.label }}</span></label
          >
        </div>
      </fieldset>
      <div class="field">
        <label for="idea-files"
          >{{ t('ui.haveAnExampleOrScreenshot') }}
          <span class="optional-label">{{ t('ui.optional') }}</span></label
        ><AttachmentList
          v-if="task?.attachments.length"
          :attachments="task.attachments"
        /><FileUploader v-model="attachments" @busy="processingFiles = $event" />
      </div>
    </div>
    <p v-if="error" class="field-error" role="alert">{{ formatApiError(error) }}</p>
    <div class="form-actions">
      <RouterLink
        :to="task ? store.taskPath(task.id) : store.projectPath"
        class="button secondary"
        >{{ t('ui.cancel') }}</RouterLink
      ><LoadingButton
        :busy="saving || processingFiles"
        class="button primary"
        type="submit"
        :disabled="saving || processingFiles || store.storageBlocked"
      >
        <AppIcon :name="task ? 'check' : 'plus'" :size="18" />{{
          t(task ? 'flow.saveIdea' : 'ui.addIdea')
        }}
      </LoadingButton>
    </div>
  </form>
</template>
