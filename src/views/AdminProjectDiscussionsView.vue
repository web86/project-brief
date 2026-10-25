<script setup>
import { ref, computed, onUnmounted, watch, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { t, formatDate, formatApiError } from '../i18n/index.js'
import { useDiscussionsStore, useClientDiscussionsStore } from '../stores/discussions.js'
import { toast } from '../services/toast.js'
import LoadingButton from '../components/LoadingButton.vue'
const route = useRoute()
const client = route.meta.clientSection === 'discussions'
const discussions = client ? useClientDiscussionsStore() : useDiscussionsStore()
const pending = ref(false),
  targetError = ref('')
const kinds = ['general', 'tasks']
const activeKind = ref(discussions.kindForComment(route.hash.slice(9)) || 'general')
let active = true,
  revealRevision = 0
onUnmounted(() => {
  active = false
  revealRevision++
})
const text = computed({
  get: () => discussions.drafts[route.params.uuid] || '',
  set: (value) => {
    discussions.drafts[route.params.uuid] = value
  },
})
async function reveal() {
  const current = ++revealRevision,
    id = route.params.uuid,
    uuid = /^#comment-([a-zA-Z0-9-]+)$/.exec(route.hash)?.[1]
  targetError.value = ''
  if (!uuid || discussions.projectId !== id || typeof document === 'undefined') return
  try {
    const kind = await discussions.revealComment(uuid)
    if (!active || current !== revealRevision || route.params.uuid !== id || !kind) return
    activeKind.value = kind
    await nextTick()
    const element = document.getElementById('comment-' + uuid)
    element?.scrollIntoView({ block: 'center' })
    element?.focus({ preventScroll: true })
  } catch (cause) {
    if (active && current === revealRevision)
      targetError.value = [404, 422].includes(cause.status)
        ? t('discussions.targetUnavailable')
        : formatApiError(cause)
  }
}
watch(
  () => route.params.uuid,
  () => {
    activeKind.value = 'general'
  },
)
watch([() => route.hash, () => route.params.uuid, () => discussions.projectId], reveal, {
  immediate: true,
  flush: 'post',
})
function selectTab(kind) {
  revealRevision++
  activeKind.value = kind
}
async function keyTab(event, index) {
  const destination = { ArrowRight: (index + 1) % 2, ArrowLeft: (index + 1) % 2, Home: 0, End: 1 }[
    event.key
  ]
  if (destination === undefined) return
  event.preventDefault()
  selectTab(kinds[destination])
  await nextTick()
  document.getElementById('discussion-tab-' + kinds[destination])?.focus()
}
async function run(action) {
  if (pending.value) return
  pending.value = true
  try {
    await action()
  } catch (error) {
    if (active) toast.error(() => formatApiError(error))
  } finally {
    if (active) pending.value = false
  }
}
function loadMore(kind) {
  const id = route.params.uuid
  return discussions.more(kind).catch((error) => {
    if (active && route.params.uuid === id) toast.error(() => formatApiError(error))
  })
}
async function send() {
  await run(async () => {
    await discussions.send(text.value)
  })
}
</script>
<template>
  <div class="discussion-page" :aria-busy="pending">
    <div class="discussion-tabs" role="tablist" :aria-label="t('discussions.title')">
      <button
        v-for="(kind, index) in kinds"
        :id="'discussion-tab-' + kind"
        :key="kind"
        type="button"
        role="tab"
        :aria-selected="activeKind === kind"
        :aria-controls="'discussion-panel-' + kind"
        :tabindex="activeKind === kind ? 0 : -1"
        @click="selectTab(kind)"
        @keydown="keyTab($event, index)"
      >
        <span>{{ t('discussions.' + kind) }}</span
        ><span
          v-if="discussions.unreadCounts[kind]"
          class="discussion-badge"
          :aria-label="t('discussions.unreadLabel', { count: discussions.unreadCounts[kind] })"
          >{{ discussions.unreadCounts[kind] }}</span
        >
      </button>
    </div>
    <p v-if="targetError" class="field-error" role="alert">{{ targetError }}</p>
    <section
      v-for="kind in kinds"
      v-show="activeKind === kind"
      :id="'discussion-panel-' + kind"
      :key="kind"
      class="surface"
      role="tabpanel"
      :aria-labelledby="'discussion-tab-' + kind"
      :tabindex="0"
    >
      <div class="discussion-toolbar">
        <h2 class="sr-only">{{ t('discussions.' + kind) }}</h2>
        <button
          v-if="discussions.targetWindows[kind]"
          class="text-button"
          :disabled="pending"
          @click="run(() => discussions.latest(kind))"
        >
          {{ t('discussions.showLatest') }}
        </button>
        <button
          class="text-button"
          :disabled="pending || !discussions.unreadCounts[kind]"
          @click="run(() => discussions.mark(route.params.uuid, [], true, kind))"
        >
          {{ t('discussions.markTab') }}
        </button>
      </div>
      <div class="discussion-feed">
        <article
          v-for="comment in discussions[kind]"
          :key="comment.id"
          tabindex="-1"
          :id="`comment-${comment.id}`"
          class="discussion-item"
          :class="{ 'is-unread': comment.unread }"
        >
          <div class="comment-heading">
            <strong>{{ comment.authorName }}</strong
            ><time :datetime="comment.createdAt">{{ formatDate(comment.createdAt) }}</time>
          </div>
          <strong v-if="comment.task" class="discussion-task-title"
            >{{ comment.task.title
            }}<span v-if="comment.task.section" class="small muted">
              · {{ comment.task.section }}</span
            ></strong
          >
          <p>
            {{
              kind === 'tasks' && comment.text.length > 600
                ? comment.text.slice(0, 600) + '…'
                : comment.text
            }}
          </p>
          <div class="discussion-actions">
            <RouterLink
              v-if="comment.task"
              :to="
                client
                  ? `/project/${route.params.uuid}/task/${comment.task.id}#comment-${comment.id}`
                  : `/admin/projects/${route.params.uuid}/brief/task/${comment.task.id}#comment-${comment.id}`
              "
              class="text-button"
              >{{ t('discussions.openTask') }}</RouterLink
            >
            <button
              v-if="comment.unread"
              class="text-button"
              :disabled="pending"
              @click="run(() => discussions.mark(route.params.uuid, [comment.id]))"
            >
              {{ t('discussions.markRead') }}
            </button>
          </div>
        </article>
        <p v-if="!discussions[kind].length" class="muted">{{ t('discussions.empty') }}</p>
      </div>
      <LoadingButton
        v-if="discussions.next[kind]"
        :busy="!!discussions.loadingMore[kind]"
        :disabled="pending || !!discussions.loadingMore[kind]"
        class="button secondary"
        @click="loadMore(kind)"
        >{{ t('discussions.more') }}</LoadingButton
      >
      <form v-if="kind === 'general'" class="comment-form" @submit.prevent="send">
        <label for="project-comment">{{ t('discussions.message') }}</label>
        <textarea
          id="project-comment"
          v-model="text"
          rows="3"
          maxlength="5000"
          :disabled="pending"
        />
        <div class="comment-form-footer">
          <LoadingButton
            type="submit"
            class="button primary"
            :busy="pending"
            :disabled="pending || !text.trim()"
            >{{ t('ui.send') }}</LoadingButton
          >
        </div>
      </form>
    </section>
  </div>
</template>
