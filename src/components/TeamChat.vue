<script setup>
import { ref, watch, nextTick, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import { useTeamChatStore } from '../stores/teamChat.js'
import { t, formatDate, formatApiError, intlLocale } from '../i18n/index.js'
import CommentForm from './CommentForm.vue'
import AttachmentList from './AttachmentList.vue'
import AppIcon from './AppIcon.vue'
const props = defineProps({ active: Boolean })
const chat = useTeamChatStore(),
  route = useRoute(),
  feed = ref(null),
  error = ref(''),
  busy = ref(false)
let alive = true
onUnmounted(() => {
  alive = false
})
const day = (row) => new Date(row.createdAt).toLocaleDateString('en-CA')
const dateLabel = (row) =>
  new Intl.DateTimeFormat(intlLocale(), { year: 'numeric', month: 'long', day: 'numeric' }).format(
    new Date(row.createdAt),
  )
async function refresh(older = false) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await chat.load(route.params.uuid, older)
    if (props.active) await chat.read()
  } catch (cause) {
    if (alive) error.value = formatApiError(cause)
  } finally {
    if (alive) busy.value = false
  }
}
watch(
  () => route.params.uuid,
  () => refresh(),
  { immediate: true },
)
watch([() => props.active, () => chat.messages.length], async () => {
  if (!props.active) return
  try {
    await chat.read()
  } catch (cause) {
    if (alive) error.value = formatApiError(cause)
  }
  await nextTick()
  const uuid = /^#team-([a-zA-Z0-9-]+)$/.exec(route.hash)?.[1]
  if (uuid) document.getElementById('team-' + uuid)?.scrollIntoView({ block: 'center' })
})
async function send(text, files, submissionId) {
  const result = await chat.send(text, files, submissionId)
  await nextTick()
  if (result)
    feed.value?.scrollTo({
      top: feed.value.scrollHeight,
      behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
    })
  return result
}
</script>
<template>
  <section
    id="discussion-panel-team"
    role="tabpanel"
    aria-labelledby="discussion-tab-team"
    tabindex="0"
    class="surface team-chat"
    :aria-label="t('team95.chat')"
    :aria-busy="busy"
  >
    <header class="team-chat-heading">
      <h2><AppIcon name="message" />{{ t('team95.chat') }}</h2>
      <button class="text-button" :disabled="busy" @click="refresh()">
        {{ t('liveSync.checkMessages') }}
      </button>
    </header>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <div
      ref="feed"
      class="team-chat-feed"
      role="log"
      :aria-label="t('team95.chat')"
      aria-live="polite"
      aria-relevant="additions"
    >
      <button v-if="chat.before" class="text-button" :disabled="busy" @click="refresh(true)">
        {{ t('discussions.more') }}
      </button>
      <template v-for="(row, index) in chat.messages" :key="row.id">
        <p v-if="!index || day(row) !== day(chat.messages[index - 1])" class="chat-date">
          {{ dateLabel(row) }}
        </p>
        <article
          :id="'team-' + row.id"
          class="team-chat-message"
          :class="{
            own: row.own,
            consecutive:
              index &&
              row.authorId === chat.messages[index - 1].authorId &&
              day(row) === day(chat.messages[index - 1]),
          }"
          tabindex="-1"
        >
          <img v-if="row.avatarUrl" class="chat-avatar" :src="row.avatarUrl" alt="" /><span
            v-else
            class="chat-avatar initials"
            aria-hidden="true"
            >{{ row.authorName?.slice(0, 2).toUpperCase() }}</span
          >
          <div class="chat-bubble">
            <div class="chat-author">
              <strong>{{ row.authorName }}</strong
              ><time :datetime="row.createdAt">{{ formatDate(row.createdAt) }}</time>
            </div>
            <p>{{ row.text }}</p>
            <AttachmentList :attachments="row.attachments || []" compact />
          </div>
        </article>
      </template>
      <p v-if="!chat.messages.length && !busy" class="muted">{{ t('discussions.empty') }}</p>
    </div>
    <CommentForm
      :key="route.params.uuid"
      v-model="chat.draft"
      :label="t('team95.message')"
      general
      :send-comment="send"
    />
  </section>
</template>
<style scoped>
.team-chat {
  padding: 1rem;
  min-width: 0;
}
.team-chat-heading,
.team-chat-heading h2 {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
}
.team-chat-heading {
  justify-content: space-between;
  margin-bottom: 1rem;
}
.team-chat-heading h2 {
  margin: 0;
  font-size: 1.05rem;
}
.team-chat-feed {
  max-height: 58dvh;
  overflow-y: auto;
  overflow-wrap: anywhere;
  padding: 0.25rem;
}
.chat-date {
  text-align: center;
  font-size: 0.75rem;
  color: var(--muted);
  margin: 1rem 0;
}
.team-chat-message {
  display: flex;
  gap: 0.6rem;
  align-items: flex-start;
  margin: 0.9rem 0;
  max-width: 90%;
}
.team-chat-message.own {
  margin-left: auto;
  flex-direction: row-reverse;
}
.chat-avatar {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
}
.initials {
  display: grid;
  place-items: center;
  background: var(--surface-muted);
  font-size: 0.7rem;
}
.chat-bubble {
  background: var(--surface-muted);
  padding: 0.65rem 0.8rem;
  border-radius: 12px;
  min-width: 0;
  border: 1px solid var(--border);
}
.own .chat-bubble {
  border-color: var(--accent);
}
.chat-author {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  font-size: 0.8rem;
  align-items: baseline;
}
.chat-author time {
  font-size: 0.7rem;
  color: var(--muted);
}
.chat-bubble p {
  white-space: pre-wrap;
  margin: 0.4rem 0 0;
}
.consecutive {
  margin-top: -0.45rem;
}
.consecutive .chat-author strong,
.consecutive .chat-avatar {
  visibility: hidden;
}
@media (max-width: 600px) {
  .team-chat-message {
    max-width: 100%;
  }
  .team-chat {
    padding: 0.75rem;
  }
}
</style>
