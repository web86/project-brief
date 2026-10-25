<script setup>
import { ref, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { t } from '../i18n/index.js'

import { formatDate } from '../constants/project'
import UserAvatar from './UserAvatar.vue'
import AppIcon from './AppIcon.vue'
import AttachmentList from './AttachmentList.vue'
const props = defineProps({ comments: Array, target: String })
const emit = defineEmits(['seen'])
const container = ref(null),
  highlighted = ref('')
let observer,
  timer,
  lastTarget = ''
async function sync() {
  await nextTick()
  observer?.disconnect()
  if (typeof IntersectionObserver !== 'undefined') {
    observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries)
          if (entry.isIntersecting) {
            emit('seen', entry.target.dataset.commentId)
            observer.unobserve(entry.target)
          }
      },
      { threshold: 0.5 },
    )
    container.value
      ?.querySelectorAll('[data-comment-id]')
      .forEach((element) => observer.observe(element))
  }
  if (props.target && props.target !== lastTarget) {
    const element = [...(container.value?.querySelectorAll('[data-comment-id]') || [])].find(
      (element) => 'comment-' + element.dataset.commentId === props.target,
    )
    if (element) {
      lastTarget = props.target
      element.scrollIntoView({ block: 'center', behavior: 'instant' })
      highlighted.value = element.dataset.commentId
      clearTimeout(timer)
      timer = setTimeout(() => {
        highlighted.value = ''
      }, 2500)
    }
  }
}
onMounted(sync)
watch(() => [props.comments, props.target], sync)
onUnmounted(() => {
  observer?.disconnect()
  clearTimeout(timer)
})
</script>
<template>
  <div v-if="comments.length" ref="container" class="comment-list" :aria-label="t('ui.comments2')">
    <article
      v-for="comment in comments"
      :key="comment.id"
      :id="'comment-' + comment.id"
      :data-comment-id="comment.id"
      class="comment"
      :class="[comment.author, { 'is-target': highlighted === comment.id }]"
    >
      <UserAvatar
        v-if="comment.author === 'developer' && comment.avatarUrl"
        :user="{ name: comment.authorName, avatarUrl: comment.avatarUrl }"
        class="comment-avatar"
      />
      <span v-else class="comment-avatar"
        ><AppIcon :name="comment.author === 'developer' ? 'code' : 'user'" :size="17"
      /></span>
      <div class="comment-content">
        <div class="comment-heading">
          <strong>{{
            (comment.authorId ? comment.authorName : null) ||
            (comment.author === 'developer' ? t('ui.developer') : t('ui.client'))
          }}</strong
          ><time :datetime="comment.createdAt">{{ formatDate(comment.createdAt) }}</time>
        </div>
        <p v-if="comment.text">{{ comment.text }}</p>
        <AttachmentList :attachments="comment.attachments || []" compact />
      </div>
    </article>
  </div>
  <div v-else class="comments-empty">
    <p>{{ t('ui.noCommentsYet') }}</p>
  </div>
</template>
