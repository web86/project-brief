<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useProjectStore } from '../stores/project'
import { PRIORITIES } from '../constants/project'
import FileUploader from './FileUploader.vue'
import LocationInput from './LocationInput.vue'
import AiTaskAssistant from './AiTaskAssistant.vue'
import AppIcon from './AppIcon.vue'
const store = useProjectStore()
const router = useRouter()
const title = ref('')
const location = ref('')
const description = ref('')
const expectedResult = ref('')
const priority = ref('normal')
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
    store.addTask({
      title: title.value,
      location: location.value,
      description: description.value,
      expectedResult: expectedResult.value,
      priority: priority.value,
      attachments: attachments.value,
    })
    await router.push('/')
  } catch (cause) {
    error.value = cause.message
  } finally {
    saving.value = false
  }
}
</script>
<template>
  <form class="idea-form" novalidate @submit.prevent="submit">
    <div class="field">
      <label for="idea-title"
        >Что хотите изменить? <span class="required-dot" aria-hidden="true">*</span></label
      ><input
        id="idea-title"
        v-model="title"
        placeholder="Например: сделать фотографии товара крупнее"
        maxlength="160"
        required
        :aria-invalid="submitted && errors.title"
        :aria-describedby="submitted && errors.title ? 'title-error' : undefined"
      />
      <p v-if="submitted && errors.title" id="title-error" class="field-error">
        Коротко назовите свою идею.
      </p>
    </div>
    <div class="field">
      <label for="idea-location"
        >Где это находится? <span class="required-dot" aria-hidden="true">*</span></label
      ><LocationInput
        id="idea-location"
        v-model="location"
        :suggestions="store.locationOptions"
        :invalid="submitted && errors.location"
        :described-by="submitted && errors.location ? 'location-error' : undefined"
      />
      <p v-if="submitted && errors.location" id="location-error" class="field-error">
        Укажите страницу, элемент сайта или ссылку.
      </p>
    </div>
    <div class="field">
      <div class="field-label-row">
        <label for="idea-description"
          >Расскажите подробнее <span class="required-dot" aria-hidden="true">*</span></label
        ><AiTaskAssistant :description="description" />
      </div>
      <textarea
        id="idea-description"
        v-model="description"
        placeholder="Опишите своими словами, что сейчас не нравится или что хотелось бы изменить."
        rows="4"
        maxlength="10000"
        required
        :aria-invalid="submitted && errors.description"
        :aria-describedby="submitted && errors.description ? 'description-error' : undefined"
      />
      <p v-if="submitted && errors.description" id="description-error" class="field-error">
        Добавьте немного подробностей, чтобы мы поняли вашу идею.
      </p>
    </div>
    <div class="optional-fields">
      <div class="field">
        <label for="idea-result"
          >Какой результат вы ожидаете? <span class="optional-label">необязательно</span></label
        ><textarea
          id="idea-result"
          v-model="expectedResult"
          placeholder="Например: хочу, чтобы фотографии можно было листать и открывать крупнее."
          rows="2"
          maxlength="10000"
        />
      </div>
      <fieldset class="field priority-field">
        <legend>Насколько это важно?</legend>
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
          >Есть пример или скриншот? <span class="optional-label">необязательно</span></label
        ><FileUploader v-model="attachments" @busy="processingFiles = $event" />
      </div>
    </div>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <div class="form-actions">
      <RouterLink to="/" class="button secondary">Отмена</RouterLink
      ><button
        class="button primary"
        type="submit"
        :disabled="saving || processingFiles || store.storageBlocked"
      >
        <AppIcon name="plus" :size="18" />{{
          processingFiles ? 'Обрабатываем файлы…' : saving ? 'Добавляем…' : 'Добавить идею'
        }}
      </button>
    </div>
  </form>
</template>
