<script setup>
import { computed, inject, ref } from 'vue'
import { useProjectStore } from '../stores/project.js'
import { taskDragKey } from '../utils/taskDrag.js'
import { t } from '../i18n/index.js'
import AppIcon from './AppIcon.vue'
import BaseDialog from './BaseDialog.vue'
import LoadingButton from './LoadingButton.vue'
const props = defineProps({ task: { type: Object, required: true } })
const store = useProjectStore()
const drag = inject(taskDragKey, null)
const open = ref(false)
const sectionId = ref('')
const beforeId = ref('')
const disabled = computed(() => !drag?.enabled.value || store.orderPending || store.storageBlocked)
const siblings = computed(() =>
  store.sectionTasks(sectionId.value).filter((task) => task.id !== props.task.id),
)
function show(event) {
  if (disabled.value || (event.detail !== 0 && drag?.suppressClick())) return
  sectionId.value = props.task.sectionId
  beforeId.value = ''
  open.value = true
}
async function place() {
  if (disabled.value) return
  if (await store.placeTask(props.task.id, sectionId.value, beforeId.value || null))
    open.value = false
}
async function step(direction) {
  const items = store.sectionTasks(props.task.sectionId)
  const index = items.findIndex((task) => task.id === props.task.id)
  const anchor = items[index + direction]
  if (!anchor || disabled.value) return
  if (
    await store.placeTask(
      props.task.id,
      props.task.sectionId,
      direction < 0 ? anchor.id : null,
      direction > 0 ? anchor.id : null,
    )
  )
    open.value = false
}
</script>
<template>
  <div class="task-move-control">
    <button
      type="button"
      class="task-grip"
      :disabled="disabled"
      :aria-label="t('management.moveNamed', { title: task.title })"
      aria-haspopup="dialog"
      :title="t(disabled ? 'management.filterHint' : 'management.dragHint')"
      @pointerdown="drag?.begin($event, task.id)"
      @click.stop.prevent="show"
      @dragstart.prevent
    >
      <AppIcon name="grip" :size="21" />
    </button>
    <BaseDialog
      :open="open"
      :title="t('management.moveIdea')"
      :id="`move-${task.id}`"
      @close="!store.orderPending && (open = false)"
    >
      <p class="move-task-title">{{ task.title }}</p>
      <div class="move-step-actions">
        <button
          class="button secondary"
          :disabled="disabled || store.sectionTasks(task.sectionId)[0]?.id === task.id"
          @click="step(-1)"
        >
          {{ t('management.up') }}
        </button>
        <button
          class="button secondary"
          :disabled="disabled || store.sectionTasks(task.sectionId).at(-1)?.id === task.id"
          @click="step(1)"
        >
          {{ t('management.down') }}
        </button>
      </div>
      <label class="move-field"
        >{{ t('management.section') }}
        <select v-model="sectionId" :disabled="store.orderPending" @change="beforeId = ''">
          <option v-for="section in store.sectionOptions" :key="section.id" :value="section.id">
            {{ section.name }}
          </option>
        </select>
      </label>
      <label class="move-field"
        >{{ t('management.position') }}
        <select v-model="beforeId" :disabled="store.orderPending">
          <option value="">{{ t('management.atEnd') }}</option>
          <option v-for="item in siblings" :key="item.id" :value="item.id">
            {{ t('management.before', { title: item.title }) }}
          </option>
        </select>
      </label>
      <template #footer>
        <button class="button secondary" :disabled="store.orderPending" @click="open = false">
          {{ t('ui.cancel') }}
        </button>
        <LoadingButton
          class="button primary"
          :disabled="disabled"
          :busy="store.orderPending"
          @click="place"
          >{{ t('management.move') }}</LoadingButton
        >
      </template>
    </BaseDialog>
  </div>
</template>
