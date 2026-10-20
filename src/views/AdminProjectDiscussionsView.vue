<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { t, formatDate, formatApiError } from '../i18n/index.js'
import { useDiscussionsStore, useClientDiscussionsStore } from '../stores/discussions.js'
import { toast } from '../services/toast.js'
import LoadingButton from '../components/LoadingButton.vue'
const route = useRoute()
const client = route.meta.clientSection === 'discussions'
const discussions = client ? useClientDiscussionsStore() : useDiscussionsStore()
const pending = ref(false)
const text = computed({
  get: () => discussions.drafts[route.params.uuid] || '',
  set: (value) => {
    discussions.drafts[route.params.uuid] = value
  },
})
async function reveal() {
  if (!route.hash.startsWith('#comment-')) return
  await nextTick()
  const element = document.getElementById(route.hash.slice(1))
  element?.scrollIntoView({ block: 'center' })
}
onMounted(reveal)
watch(() => route.hash, reveal)
async function run(action) {
  if (pending.value) return
  pending.value = true
  try {
    await action()
  } catch (error) {
    toast.error(() => formatApiError(error))
  } finally {
    pending.value = false
  }
}
async function send() {
  await run(async () => {
    await discussions.send(text.value)
  })
}
</script>
<template>
  <div class="discussion-page" :aria-busy="pending">
    <div class="discussion-toolbar">
      <div class="filter-tabs" role="group" :aria-label="t('discussions.title')">
        <button
          v-for="filter in ['all', 'unread']"
          :key="filter"
          :class="{ active: discussions.filter === filter }"
          :aria-pressed="discussions.filter === filter"
          :disabled="pending"
          @click="run(() => discussions.load(route.params.uuid, { selectedFilter: filter }))"
        >
          {{ t('discussions.' + filter) }}
        </button>
      </div>
      <button
        class="text-button"
        :disabled="pending || !discussions.unreadCount"
        @click="run(() => discussions.mark(route.params.uuid, [], true))"
      >
        {{ t('discussions.markAll') }}
      </button>
    </div>
    <section v-for="kind in ['general', 'tasks']" :key="kind" class="surface">
      <div class="discussion-toolbar">
        <h2>{{ t('discussions.' + kind) }}</h2>
      </div>
      <div class="discussion-feed">
        <article
          v-for="comment in discussions[kind]"
          :key="comment.id"
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
        :busy="pending"
        class="button secondary"
        @click="run(() => discussions.more(kind))"
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
