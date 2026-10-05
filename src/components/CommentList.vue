<script setup>
import { formatDate } from '../constants/project'
import AppIcon from './AppIcon.vue'
defineProps({ comments: Array })
</script>
<template>
  <div v-if="comments.length" class="comment-list" aria-label="Комментарии">
    <article v-for="comment in comments" :key="comment.id" class="comment" :class="comment.author">
      <span class="comment-avatar"
        ><AppIcon :name="comment.author === 'developer' ? 'code' : 'user'" :size="17"
      /></span>
      <div class="comment-content">
        <div class="comment-heading">
          <strong>{{
            comment.authorName || (comment.author === 'developer' ? 'Разработчик' : 'Клиент')
          }}</strong
          ><time :datetime="comment.createdAt">{{ formatDate(comment.createdAt) }}</time>
        </div>
        <p>{{ comment.text }}</p>
      </div>
    </article>
  </div>
  <div v-else class="comments-empty">
    <p>Комментариев пока нет.</p>
  </div>
</template>
